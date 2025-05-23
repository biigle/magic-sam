import Feature from '@biigle/ol/Feature';
import MagicWand from 'magic-wand-tool';
import npyjs from "npyjs";
import PointerInteraction from '@biigle/ol/interaction/Pointer';
import Polygon from '@biigle/ol/geom/Polygon';
import VectorLayer from '@biigle/ol/layer/Vector';
import VectorSource from '@biigle/ol/source/Vector';
import {InferenceSession, Tensor} from "onnxruntime-web";
import {linearRingContainsXY} from '@biigle/ol/geom/flat/contains';
import {throttle} from '../import';

const LONG_SIDE_LENGTH = 1024;

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
        this.extent = null;

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

    updateEmbedding(url, embedding, extent) {
        let width = Math.floor(Math.abs(extent[2] - extent[0]));
        let height = Math.floor(Math.abs(extent[3] - extent[1]));

        this.imageSizeTensor = new Tensor("float32", [height, width]);
        this.imageSamScale = LONG_SIDE_LENGTH / Math.max(height, width);
        this.samSizeTensor = new Tensor("float32", [
            Math.floor(height * this.imageSamScale),
            Math.floor(width * this.imageSamScale),
        ]);

        this.extent = extent;

        let npy = new npyjs();

        // Maybe the model is not initialized at this point so we have to wait for that,
        // too.
        let promise = null;
        if (embedding) {
            promise = Vue.Promise.all([this.initPromise])
                .then(() => {
                    let npArray = npy.parse(embedding);
                    this.embedding = new Tensor("float32", npArray.data, npArray.shape);
                    this._runModelWarmup();
                });
        } else {
             promise = Vue.Promise.all([npy.load(url), this.initPromise])
                .then(([npArray,]) => {
                    this.embedding = new Tensor("float32", npArray.data, npArray.shape);
                    this._runModelWarmup();
                });
        }
        return promise;
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
        if (!this.model) {
            return;
        }

        // Do this not faster than once per second.
        throttle(() => {
            let [height, ] = this.imageSizeTensor.data;

            let pointCoords = new Float32Array([
                ((e.coordinate[0] - this.extent[0]) * this.imageSamScale),
                // Move origin to image section origin and
                // add y coordinate because y-axis is inverted
                (((height - e.coordinate[1]) + this.extent[1]) * this.imageSamScale),
                // Add in the extra point when only clicks and no box.
                // The extra point is at (0, 0) with label -1.
                0,
                0,
            ]);

            let pointCoordsTensor = new Tensor("float32", pointCoords, [1, 2, 2]);
            const feeds = this._getFeeds(pointCoordsTensor);

            this.model.run(feeds).then(this._processInferenceResult.bind(this, pointCoords));
        }, this.throttleInterval, 'magic-sam-move');

    }

    getSketchFeatureBoundingBox() {
        return this.sketchFeature.getGeometry().getExtent();
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

        let [height, ] = this.imageSizeTensor.data;
        let [samHeight, samWidth] = this.samSizeTensor.data;

        const output = results[this.model.outputNames[0]];

        const thresholdedOutput = output.data.map(pixel => pixel > 0 ? 1 : 0);

        let imageData = {
            data: new Uint8Array(thresholdedOutput),
            width: samWidth,
            height: samHeight,
            bounds: {
                minX: 0,
                maxX: samWidth,
                minY: 0,
                maxY: samHeight
            },
        };

        let contour = MagicWand.traceContours(imageData)
            .filter(c => !c.inner)
            .filter(c => contourContainsPoint(c, pointCoords))
            .shift();

        if (!contour) {
            return;
        }

        if (this.simplifyTolerant > 0) {
            contour = MagicWand.simplifyContours([contour], this.simplifyTolerant, this.simplifyCount).shift();
        }

        let points = contour.points.map(point => [point.x, point.y])
            // Scale up to original size.
            .map(([x, y]) => [x / this.imageSamScale, y / this.imageSamScale])
            // Project points into image section and
            // invert y axis for OpenLayers coordinates.
            .map(([x, y]) => [x + this.extent[0], (height - y) + this.extent[1]]);

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
            this.dispatchEvent({ type: 'drawstart' });
            this.sketchSource.clear();
            this.sketchSource.addFeature(this.sketchFeature);
        }
    }

}

export default MagicSamInteraction;
