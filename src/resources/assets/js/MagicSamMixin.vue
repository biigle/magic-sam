<script>
import ImageEmbeddingApi from './api/image';
import MagicSamInteraction from './ol/MagicSamInteraction';
import {Echo} from './import';
import {handleErrorResponse} from './import';
import {Keyboard} from './import';
import {Messages} from './import';
import {Styles} from './import';
import {Events} from './import';
import { computeTileGroup, computeTotalTilesIndex } from './utils';
import Polygon from '@biigle/ol/geom/Polygon.js';
import Feature from '@biigle/ol/Feature';
import VectorLayer from '@biigle/ol/layer/Vector';
import VectorSource from '@biigle/ol/source/Vector';

let magicSamInteraction;
let loadedImageId;
let loadingImageId;

/**
 * Mixin for the annotationCanvas component that contains logic for the Magic Sam interaction.
 *
 * @type {Object}
 */
export default {
    data: function () {
        return {
            loadingMagicSam: false,
            loadingMagicSamTakesLong: false,
            throttleInterval: 1000,
            embeddingId: 0,
            targetSize: 1024,
            startDrawing: false,
            focusLayer: null,
            focusModus: false,
            prevExtent: [],
            prevEmbedding: [],
        };
    },
    computed: {
        isMagicSamming() {
            return this.interactionMode === 'magicSam';
        },
        magicSamButtonClass() {
            return this.loadingMagicSamTakesLong ? 'loading-magic-sam-long' : '';
        },
        magicSamButtonTitle() {
            if (this.loadingMagicSamTakesLong) {
                return 'Preparing the magic for this image';
            }

            return 'Draw a polygon using the magic SAM tool 𝗭';
        },
    },
    methods: {
        setThrottleInterval(interval) {
            this.throttleInterval = interval;
            if (magicSamInteraction) {
                magicSamInteraction.setThrottleInterval(interval);
            }
        },
        startLoadingMagicSam() {
            this.loadingMagicSam = true;
        },
        finishLoadingMagicSam() {
            this.loadingMagicSam = false;
            this.loadingMagicSamTakesLong = false;
        },
        toggleMagicSam() {
            if (this.isMagicSamming) {
                this.resetInteractionMode();
            } else if (this.canAdd) {
                if (!magicSamInteraction) {
                    this.initMagicSamInteraction();
                }
                this.interactionMode = 'magicSam';
            }
        },
        handleSamEmbeddingRequestSuccess(responseBody = null) {
            if (this.image.id !== loadingImageId) {
                return;
            }

            if (responseBody !== null) {
                this.handleSamEmbeddingAvailable(responseBody);
            } else {
                // Wait for the Websockets event.
                this.loadingMagicSamTakesLong = true;
            }
        },
        handleSamEmbeddingRequestFailure(response) {
            this.resetInteractionMode();
            this.finishLoadingMagicSam();
            if (response.status == 429) {
                Messages.warning("A SAM job is still running. Please retry later.")
            } else {
                handleErrorResponse(response);
            }
        },
        handleSamEmbeddingAvailable(response) {
            if (!this.loadingMagicSam) {
                return;
            }

            if (this.image.id !== loadingImageId) {
                return;
            }

            let embedding = null;
            let usedExtent = null;

            if (response.embedding) {
                embedding = response.embedding;
            } else {
                embedding = response.url
            }

            this.embeddingId = response.id
            usedExtent = [...response.extent];
            this.invertPointsYAxis(usedExtent);

            loadedImageId = this.image.id;
            magicSamInteraction.updateEmbedding(embedding, usedExtent)
                .then(() => {
                    if (this.image.tiled || this.focusModus) {
                        this.drawFocusBox(response.extent)
                    }
                })
                .then(this.finishLoadingMagicSam)
                .then(() => {
                    // The user could have disabled the interaction while loading.
                    if (this.isMagicSamming) {
                        magicSamInteraction.setActive(true);
                    }
                });
        },
        handleSamEmbeddingFailed() {
            if (this.loadingMagicSam) {
                Messages.danger('Could not load the image embedding.');
                this.finishLoadingMagicSam();
                this.resetInteractionMode();
            }
        },
        initMagicSamInteraction() {
            magicSamInteraction = new MagicSamInteraction({
                map: this.map,
                source: this.annotationSource,
                style: Styles.editing,
                indicatorPointStyle: Styles.editing,
                indicatorCrossStyle: Styles.cross,
                onnxUrl: biigle.$require('magic-sam.onnx-url'),
                simplifyTolerant: 0.1,
                throttleInterval: this.throttleInterval,
            });
            magicSamInteraction.on('drawstart', () => { this.startDrawing = true;});
            magicSamInteraction.on('drawend', this.handleNewFeature);
            magicSamInteraction.setActive(false);
            this.map.addInteraction(magicSamInteraction);
        },
        processExtent(extent) {
            // Images that are smaller than the target size should be processes only as a whole 
            if (this.image.width < this.targetSize || this.image.height < this.targetSize) {
                return [0, this.image.height, this.image.width, 0];
            }

            // Set viewport values on 0 or max height or width if the viewport corners are located outside the image
            let viewport = extent.map((c, i) => {
                let size = i % 2 == 0 ? this.image.width : this.image.height;
                return Math.floor(Math.min(Math.max(0, c), size));
            });

            // Resize images that are smaller than the target size on at least on edge
            let width = viewport[2] - viewport[0];
            let height = viewport[1] - viewport[3];

            if (width < this.targetSize) {
                let diffX = (this.targetSize - width) / 2;
                if (viewport[0] < diffX) {
                    diffX = (this.targetSize - width) - viewport[0];
                    viewport[0] = 0;
                    viewport[2] += diffX;
                } else if ((viewport[2] + diffX) > this.image.width) {
                    diffX = (this.targetSize - width) - (this.image.width - viewport[2]);
                    viewport[0] -= diffX;
                    viewport[2] = this.image.width;
                } else {
                    viewport[0] -= diffX
                    viewport[2] += diffX
                }
            }

            if (height < this.targetSize) {
                let diffY = (this.targetSize - height) / 2;
                if ((viewport[1] + diffY) > this.image.height) {
                    diffY = (this.targetSize - height) - (this.image.height - viewport[1])
                    viewport[1] = this.image.height
                    viewport[3] -= diffY
                } else if (viewport[3] < diffY) {
                    diffY = (this.targetSize - height) - viewport[3]
                    viewport[3] = 0
                    viewport[1] += diffY
                } else {
                    viewport[1] += diffY
                    viewport[3] -= diffY
                }
            }

            return viewport;
        },
        requestImageEmbedding(body) {
            this.startLoadingMagicSam();

            return ImageEmbeddingApi.save({ id: this.image.id }, body)
                .then((res) => {
                    if (res.body) {
                        let id = parseInt(res.headers.map['x-embedding-id'][0]);
                        let extent = JSON.parse(res.headers.map['x-embedding-extent']);
                        res.body.arrayBuffer().then((buffer) => {
                            this.handleSamEmbeddingRequestSuccess({ id: id, extent: extent, embedding: buffer });
                        });
                    } else {
                        this.handleSamEmbeddingRequestSuccess();
                    }
                },
                    this.handleSamEmbeddingRequestFailure
                ).catch(handleErrorResponse);
        },
        requestRefinedEmbedding() {
            if (this.focusModus) {
                return;
            }

            // Disable focus for too small images
            if (this.image.width < this.targetSize || this.image.height < this.targetSize){
                return
            }

            if (this.startDrawing) {
                let featureExtent = [...magicSamInteraction.getSketchFeatureBoundingBox()];
                if (featureExtent.length > 0) {
                    this.focusModus = true;
                    this.savePrevEmbeddingData();
                    this.invertPointsYAxis(featureExtent);
                    featureExtent = this.processExtent(featureExtent);
                    this.requestImageEmbedding({ extent: featureExtent, excludeEmbeddingId: this.embeddingId });
                } else {
                    Messages.info("Please select an object before requesting refined outlines.")
                }
            }
        },
        savePrevEmbeddingData() {
            this.prevExtent = this.computeEmbeddingExtent();
            this.invertPointsYAxis(this.prevExtent);
            this.prevEmbedding = magicSamInteraction.getCurrentEmbeddingTensor();
        },
        computeEmbeddingExtent() {
            let view = this.map.getView();
            let extent = view.calculateExtent(this.map.getSize());
            this.invertPointsYAxis(extent);
            return this.processExtent(extent);
        },
        getImageTilesDescription() {
            let view = this.map.getView();

            let extent = this.computeEmbeddingExtent();
            this.invertPointsYAxis(extent);
            let zoom = this.computeZoom(view, extent);

            let source = this.tiledImageLayer.getSource();
            let tileGrid = source.getTileGridForProjection(view.getProjection());
            let tileRange = tileGrid.getTileRangeForExtentAndZ(extent, zoom);

            let totalTilesIndex = computeTotalTilesIndex(this.image.width, this.image.height);
            let tiles = []

            for (let y = tileRange.minY; y <= tileRange.maxY; y++) {
                for (let x = tileRange.minX; x <= tileRange.maxX; x++) {
                    let group = computeTileGroup(totalTilesIndex, zoom, x, y)
                    tiles.push({ 'group': group, 'zoom': zoom, 'x': x, 'y': y });
                }
            }

            return { 'tiles': tiles, 'tiledImageExtent': this.getTiledImageExtent(extent, zoom, tileGrid), 'columns': (tileRange.maxX - tileRange.minX + 1) };
        },
        getTiledImageExtent(extent, zoom, tileGrid) {
            // Lower left corner
            let ll = tileGrid.getTileCoordForCoordAndZ([extent[0], extent[1]], zoom);
            // Uper right corner
            let ur = tileGrid.getTileCoordForCoordAndZ([extent[2], extent[3]], zoom);

            // Tiles that cover the viewport can have corners outside the viewport
            let llCoords = tileGrid.getTileCoordExtent(ll);
            let urCoords = tileGrid.getTileCoordExtent(ur);

            let tiledImageExtent = [llCoords[0], llCoords[1], urCoords[2], urCoords[3]];
            this.invertPointsYAxis(tiledImageExtent);

            return tiledImageExtent;
        },
        computeZoom(view, extent) {
            let zoom = -1;
            // Prevent missing tile coordinates by using zoom of computed extent instead of current view
            // if viewport is smaller than 1024x1024
            if (this.viewExtent[0] > extent[0]
                && this.viewExtent[1] > extent[1]
                && this.viewExtent[2] < extent[2]
                && this.viewExtent[3] < extent[3]) {
                zoom = this.map.getView()
                    .getZoomForResolution(this.map.getView()
                        .getResolutionForExtent(extent, this.map.getSize()))
            } else {
                zoom = view.getZoom()
            }
            return Math.round(zoom);
        },
        drawFocusBox(extent) {
            let drawExtent = [...extent]
            this.invertPointsYAxis(drawExtent);

            let outer = [
                [this.extent[0], this.extent[3]],
                [this.extent[0], this.extent[1]],
                [this.extent[2], this.extent[1]],
                [this.extent[2], this.extent[3]],
                [this.extent[0], this.extent[3]]
            ];

            // viewport coordinates
            let inner = [
                [drawExtent[0], drawExtent[1]],
                [drawExtent[2], drawExtent[1]],
                [drawExtent[2], drawExtent[3]],
                [drawExtent[0], drawExtent[3]],
                [drawExtent[0], drawExtent[1]],
            ];

            let feature = new Feature(new Polygon([outer, inner]));
            feature.setStyle(Styles.focus);

            let source = this.focusLayer.getSource();
            source.clear();
            source.addFeature(feature);

        },
        usePreviousEmbedding() {
            if (this.focusModus) {
                this.focusModus = false;

                if (this.prevEmbedding) {
                    this.startLoadingMagicSam();
                    magicSamInteraction.updateEmbedding(this.prevEmbedding, this.prevExtent)
                        .then(() => {
                            this.prevEmbedding = null;
                            this.prevExtent = [];
                        })
                        .then(this.finishLoadingMagicSam)
                        .then(() => {
                            // The user could have disabled the interaction while loading.
                            if (this.isMagicSamming) {
                                magicSamInteraction.setActive(true);
                            }
                        });
                }
            }
        },
        clearEmbeddingData() {
            this.focusModus = false;
            this.startDrawing = false;
            this.focusLayer.getSource().clear();
            this.prevExtent = [];
            this.prevEmbedding = null;
        }
    },
    watch: {
        image(image) {
            if (this.loadingMagicSam && loadingImageId !== image.id) {
                this.finishLoadingMagicSam();
                this.resetInteractionMode();
            }

            if (this.isMagicSamming) {
                this.resetInteractionMode();
            }
        },
        isMagicSamming(active) {
            if (!active) {
                magicSamInteraction.setActive(false);
                this.clearEmbeddingData();
                return;
            }

            if (!this.hasSelectedLabel) {
                this.requireSelectedLabel();
                return;
            }

            if (loadedImageId === this.image.id) {
                magicSamInteraction.setActive(true);
            }

            if (this.loadingMagicSam) {
                return;
            }

            loadingImageId = this.image.id;

            let extent = this.computeEmbeddingExtent();
            let body = null;

            if (this.image.tiled) {
                body = Object.assign({ extent: extent }, this.getImageTilesDescription());
            } else {
                body = { extent: extent };
            }

            this.requestImageEmbedding(body);
        },
        canAdd: {
            handler(canAdd) {
                if (canAdd) {
                    Keyboard.on('z', this.toggleMagicSam, 0, this.listenerSet);
                    if (!this.image.tiled) {
                        Keyboard.on('y', this.requestRefinedEmbedding, 0, this.listenerSet);
                        Keyboard.on('x', this.usePreviousEmbedding, 0, this.listenerSet);
                    }
                } else {
                    Keyboard.off('z', this.toggleMagicSam, 0, this.listenerSet);
                    Keyboard.off('y', this.requestRefinedEmbedding, 0, this.listenerSet);
                    Keyboard.off('x', this.usePreviousEmbedding, 0, this.listenerSet);
                }
            },
            immediate: true,
        },
        focusModus(focus) {
            if (!focus) {
                this.focusLayer.getSource().clear();
            }
        },
    },
    created() {
        this.targetSize = biigle.$require('magic-sam.sam_target_size');
        Events.$on('settings.samThrottleInterval', this.setThrottleInterval);
        Echo.getInstance().private(`user-${this.userId}`)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingAvailable', this.handleSamEmbeddingAvailable)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingFailed', this.handleSamEmbeddingFailed);
    },
    mounted() {
        this.focusLayer = new VectorLayer({
            source: new VectorSource(),
            map: this.map,
        });
    }
};
</script>
