import Feature from '@biigle/ol/Feature';
import MagicWand from 'magic-wand-tool';
import npyjs from "npyjs";
import PointerInteraction from '@biigle/ol/interaction/Pointer';
import Polygon from '@biigle/ol/geom/Polygon';
import VectorLayer from '@biigle/ol/layer/Vector';
import VectorSource from '@biigle/ol/source/Vector';
import {containsCoordinate, getWidth, getHeight} from '@biigle/ol/extent';
import {InferenceSession, Tensor} from "onnxruntime-web";
import {linearRingContainsXY} from '@biigle/ol/geom/flat/contains';
import {throttle} from '../import.js';

const DEFAULT_MODEL_INPUT_SIZE = 1024;

function contourContainsPoint(contour, point) {
    let flatContour = contour.points.flatMap(p => [p.x, p.y]);
    let [px, py] = point;

    return linearRingContainsXY(flatContour, 0, flatContour.length, 2, px, py);
}

/**
 * Control for drawing polygons using the Segment Anything Model (SAM).
 */
class MagicSamInteraction extends PointerInteraction {
    constructor(options) {
        super(options);
        this.on('change:active', this.toggleActive);

        // The image layer to use as source for the magic wand tool.
        this.layer = options.layer;

        // Value to adjust simplification of the sketch polygon. Higher values result in
        // less vertices of the polygon. Set to 0 to disable simplification.
        this.simplifyTolerant = options.simplifyTolerant === undefined ? 0 :
            options.simplifyTolerant;
        // Minimum number of required vertices for the simplified polygon.
        this.simplifyCount = options.simplifyCount === undefined ? 3 :
            options.simplifyCount;

        this.map = options.map;
        this.throttleInterval = options.throttleInterval || 1000;

        this.sketchFeature = null;
        this.source = options.source;

        if (this.source === undefined) {
            this.source = new VectorSource();
            this.map.addLayer(new VectorLayer({
                source: this.source,
                zIndex: 200,
            }));
        }

        let sketchLayer = new VectorLayer({
            source: new VectorSource(),
            map: this.map,
            zIndex: 200,
        });

        this.sketchSource = sketchLayer.getSource()

        this.sketchStyle = options.style === undefined ? null : options.style;

        this.MASK_INPUT_TENSOR = new Tensor(
            "float32",
            new Float32Array(256 * 256),
            [1, 1, 256, 256]
        );
        this.HAS_MASK_INPUT_TENSOR = new Tensor("float32", [0]);
        // Add in the extra label when only clicks and no box.
        // The extra point is at (0, 0) with label -1.
        this.POINT_LABELS_TENSOR = new Tensor("float32", new Float32Array([1.0, -1.0]), [1, 2]);

        this.model = null;
        this.embedding = null;
        this.imageSizeTensor = null;
        this.samSizeTensor = null;
        this.imageSamScale = null;
        this.isDragging = false;

        this.isFrozen = false;

        this.modelInputSize = options.modelInputSize || DEFAULT_MODEL_INPUT_SIZE;

        this.detailedEmbedding = null;
        this.detailedExtent = null;
        this.detailedSamScale = null;
        this.detailedSamSizeTensor = null;

        this.pointCoordsArray = new Float32Array(4);
        this.pointCoordsTensor = new Tensor("float32", this.pointCoordsArray, [1, 2, 2]);
        this.npyLoader = new npyjs();
        this.lastMoveEvent = null;
        this.throttledHandleMove = () => {
            if (this.lastMoveEvent) {
                this._handleMove(this.lastMoveEvent);
            }
        };
        this.imageData = {
            data: null,
            width: 0,
            height: 0,
            bounds: {
                minX: 0,
                maxX: 0,
                minY: 0,
                maxY: 0
            }
        };

        // wasm needs to be present in the assets folder.
        this.initPromise = InferenceSession.create(options.onnxUrl, {
                executionProviders: ['wasm']
            })
            .then(response => this.model = response);
    }

    setThrottleInterval(value) {
        this.throttleInterval = value;
    }

    getThrottleInterval() {
        return this.throttleInterval;
    }

    updateEmbedding(image, url) {
        this.imageSizeTensor = new Tensor("float32", [image.height, image.width]);
        this.imageSamScale = this.modelInputSize / Math.max(image.height, image.width);
        this.samSizeTensor = new Tensor("float32", [
            Math.round(image.height * this.imageSamScale),
            Math.round(image.width * this.imageSamScale),
        ]);

        return Promise.all([this.npyLoader.load(url), this.initPromise])
            .then(([npArray, ]) => {
                this.embedding = new Tensor("float32", npArray.data, npArray.shape);
                this._runModelWarmup();
            });
    }

    handleUpEvent() {
        // Do not fire the event if the user was previously dragging.
        if (this.sketchFeature && !this.isDragging) {
            this.source.addFeature(this.sketchFeature);

            // Remove style to get a faster feedback that the drawing finished
            this.sketchFeature.setStyle(null);
            this.dispatchEvent({ type: 'drawend', feature: this.sketchFeature });

            this.sketchSource.removeFeature(this.sketchFeature);
            this.sketchFeature = null;
        }

        this.isDragging = false;

        return true;
    }

    handleDownEvent() {
        return true;
    }

    stopDown() {
        // The down event must be propagated so the map can still be dragged.
        return false;
    }

    handleDragEvent() {
        this.isDragging = true;
    }

    /**
     * Update the target point.
     */
    handleMoveEvent(e) {
        if (this.isFrozen || !this.model) {
            return;
        }

        this.lastMoveEvent = e;
        throttle(this.throttledHandleMove, this.throttleInterval, 'magic-sam-move');
    }

    _handleMove(e) {
        // Because of the throttling, this could be called after the interactions was disabled.
        if (!this.getActive()) {
            return;
        }

        const detailedMode = this.isDetailedModeActive();
        let xCoord;
        let yCoord;

        if (detailedMode) {
            if (!this.isPointInDetailedExtent(e.coordinate)) {
                if (this.sketchFeature && this.sketchSource.hasFeature(this.sketchFeature)) {
                    this.sketchSource.removeFeature(this.sketchFeature);
                }
                return;
            }

            // Transform coordinates relative to the detailed extent.
            // Extent is [minX, minY, maxX, maxY].
            const ext = this.detailedExtent;
            xCoord = (e.coordinate[0] - ext[0]) * this.detailedSamScale;
            yCoord = (ext[3] - e.coordinate[1]) * this.detailedSamScale;
        } else {
            const [height, ] = this.imageSizeTensor.data;
            xCoord = e.coordinate[0] * this.imageSamScale;
            yCoord = (height - e.coordinate[1]) * this.imageSamScale;
        }

        this.pointCoordsArray[0] = xCoord;
        this.pointCoordsArray[1] = yCoord;
        // pointCoordsArray[2] and [3] stay at 0 (extra point for SAM)

        const feeds = detailedMode ?
            this._getDetailedFeeds(this.pointCoordsTensor) :
            this._getFeeds(this.pointCoordsTensor);

        this.model.run(feeds).then(this._processInferenceResult.bind(this, this.pointCoordsArray));
    }

    /**
     * Update event listeners depending on the active state of the interaction.
     */
    toggleActive() {
        if (!this.getActive() && this.sketchFeature) {
            if (this.sketchSource.hasFeature(this.sketchFeature)) {
                this.sketchSource.removeFeature(this.sketchFeature);
            }
            this.sketchFeature = null;
        }
    }

    freeze() {
        this.isFrozen = true;
    }

    unfreeze() {
        this.isFrozen = false;
    }

    /**
     * Set a detailed embedding for a specific extent (detailed mode).
     * @param {Array} extent OL extent [minX, minY, maxX, maxY]
     * @param {string} url URL to load the embedding from
     */
    setDetailedEmbedding(extent, url) {
        this.detailedExtent = extent;
        const width = getWidth(extent);
        const height = getHeight(extent);
        this.detailedSamScale = this.modelInputSize / Math.max(width, height);
        this.detailedSamSizeTensor = new Tensor("float32", [
            Math.round(height * this.detailedSamScale),
            Math.round(width * this.detailedSamScale),
        ]);

        return Promise.all([this.npyLoader.load(url), this.initPromise])
            .then(([npArray, ]) => {
                this.detailedEmbedding = new Tensor("float32", npArray.data, npArray.shape);
                // Run inference again when the detailed embedding is there so the sketch
                // is immediately updated with the more detailed version.
                if (this.lastMoveEvent) {
                    this._handleMove(this.lastMoveEvent);
                }
            });
    }

    /**
     * Clear the detailed embedding and return to full-image mode.
     */
    clearDetailedEmbedding() {
        this.detailedEmbedding = null;
        this.detailedExtent = null;
        this.detailedSamScale = null;
    }

    getSketchBoundingExtent() {
        if (!this.sketchFeature) {
            return null;
        }

        const geometry = this.sketchFeature.getGeometry();

        return geometry.getExtent();
    }

    isPointInDetailedExtent(coord) {
        if (!this.detailedExtent) {
            return false;
        }

        return containsCoordinate(this.detailedExtent, coord);
    }

    isDetailedModeActive() {
        return this.detailedEmbedding !== null && this.detailedExtent !== null;
    }

    _getFeeds(pointCoordsTensor) {
        return {
            image_embeddings: this.embedding,
            point_coords: pointCoordsTensor,
            point_labels: this.POINT_LABELS_TENSOR,
            // Compute the mask on the downscaled size to make inference and tracing
            // faster. We scale the tracing result to the original size later.
            orig_im_size: this.samSizeTensor,
            mask_input: this.MASK_INPUT_TENSOR,
            has_mask_input: this.HAS_MASK_INPUT_TENSOR,
        };
    }

    _getDetailedFeeds(pointCoordsTensor) {
        return {
            image_embeddings: this.detailedEmbedding,
            point_coords: pointCoordsTensor,
            point_labels: this.POINT_LABELS_TENSOR,
            orig_im_size: this.detailedSamSizeTensor,
            mask_input: this.MASK_INPUT_TENSOR,
            has_mask_input: this.HAS_MASK_INPUT_TENSOR,
        };
    }

    _runModelWarmup() {
        // Run the model once for "warmup". After that, the interactions is
        // considered ready.
        let pointCoordsTensor = new Tensor("float32", [0, 0, 0, 0], [1, 2, 2]);
        const feeds = this._getFeeds(pointCoordsTensor);

        return this.model.run(feeds);
    }

    _processInferenceResult(pointCoords, results) {
        // Discard this result if the interaction was disabled in the meantime.
        if (!this.getActive()) {
            return;
        }

        let samHeight, samWidth, scale, height, offsetX, offsetY;

        if (this.isDetailedModeActive()) {
            const ext = this.detailedExtent;
            scale = this.detailedSamScale;
            const extWidth = getWidth(ext);
            const extHeight = getHeight(ext);
            height = extHeight;
            offsetX = ext[0];
            offsetY = ext[1];
            samHeight = Math.round(extHeight * scale);
            samWidth = Math.round(extWidth * scale);
        } else {
            [height, ] = this.imageSizeTensor.data;
            scale = this.imageSamScale;
            offsetX = 0;
            offsetY = 0;
            [samHeight, samWidth] = this.samSizeTensor.data;
        }

        const output = results[this.model.outputNames[0]];
        const outputData = output.data;
        const outputLength = outputData.length;

        if (!this.imageData.data || this.imageData.data.length !== outputLength) {
            this.imageData.data = new Uint8Array(outputLength);
        }

        for (let i = 0; i < outputLength; i++) {
            this.imageData.data[i] = outputData[i] > 0 ? 1 : 0;
        }

        this.imageData.width = samWidth;
        this.imageData.height = samHeight;
        this.imageData.bounds.maxX = samWidth;
        this.imageData.bounds.maxY = samHeight;

        let contour = MagicWand.traceContours(this.imageData)
            .filter(c => !c.inner)
            .filter(c => contourContainsPoint(c, pointCoords))
            .shift();

        if (!contour) {
            return;
        }

        if (this.simplifyTolerant > 0) {
            contour = MagicWand.simplifyContours([contour], this.simplifyTolerant, this.simplifyCount).shift();
        }

        const points = contour.points.map(point => [
            point.x / scale + offsetX,
            (height - point.y / scale) + offsetY,
        ]);

        if (this.sketchFeature) {
            this.sketchFeature.getGeometry().setCoordinates([points]);
        } else {
            this.sketchFeature = new Feature(new Polygon([points]));
            if (this.sketchStyle) {
                this.sketchFeature.setStyle(this.sketchStyle);
            }
        }

        // This happens if the sketch feature was newly created (above) or if an annotation
        // was created from the feature (which may also remove the sketch from its source).
        if (!this.sketchSource.hasFeature(this.sketchFeature)) {
            this.sketchSource.addFeature(this.sketchFeature);
        }
    }

}

export default MagicSamInteraction;
