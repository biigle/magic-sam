<script>
import Feature from '@biigle/ol/Feature';
import Fill from '@biigle/ol/style/Fill';
import ImageEmbeddingApi from './api/image.js';
import MagicSamInteraction from './ol/MagicSamInteraction.js';
import Polygon from '@biigle/ol/geom/Polygon';
import Stroke from '@biigle/ol/style/Stroke';
import Style from '@biigle/ol/style/Style';
import VectorLayer from '@biigle/ol/layer/Vector';
import VectorSource from '@biigle/ol/source/Vector';
import {Echo} from './import.js';
import {handleErrorResponse} from './import.js';
import {Keyboard} from './import.js';
import {Messages} from './import.js';
import {Styles} from './import.js';
import {Events} from './import.js';

let magicSamInteraction;
let cachedFullImageId;
let requestedImageId;
let detailedOverlayLayer;
let detailedOverlayFeature;
let detailedBorderFeature;

const OVERLAY_Z_INDEX = 199;
const OVERLAY_OPACITY = 0.75;
const OVERLAY_BORDER_COLOR = 'yellow';
const OVERLAY_BORDER_WIDTH = 3;

/**
 * Mixin for the annotationCanvas component that contains logic for the Magic Sam interaction.
 *
 * @type {Object}
 */
export default {
    data: function () {
        return {
            magicSamloadingState: null,
            magicSamloadingTakesLong: false,
            magicSamThrottleInterval: 1000,
            detailedModeActive: false,
            detailedModeBboxPadding: 100,
        };
    },
    computed: {
        isMagicSamming() {
            return this.interactionMode === 'magicSam';
        },
        isLoadingMagicSam() {
            return this.magicSamloadingState !== null;
        },
        isLoadingDetailedMode() {
            return this.magicSamloadingState === 'loading-detailed';
        },
        magicSamButtonClass() {
            return {
                'loading-magic-sam-long': this.magicSamloadingTakesLong,
                'magic-sam-detailed-mode': this.detailedModeActive,
            };
        },
        magicSamButtonTitle() {
            if (this.magicSamloadingTakesLong) {
                return 'Preparing the magic for this image';
            }

            if (this.isLoadingDetailedMode) {
                return 'Loading detailed mode...';
            }

            if (this.detailedModeActive) {
                return 'Detailed mode active (press 𝗭 to exit)';
            }

            return 'Draw a polygon using the magic SAM tool 𝗭';
        },
        magicSamTooltipText() {
            if (!this.detailedModeActive && !this.isLoadingDetailedMode) {
                return 'Press 𝗭 to toggle detailed mode';
            }

            return '';
        }
    },
    methods: {
        setThrottleInterval(interval) {
            this.magicSamThrottleInterval = interval;
            if (magicSamInteraction) {
                magicSamInteraction.setThrottleInterval(interval);
            }
        },
        startLoadingFullEmbedding() {
            this.magicSamloadingState = 'loading-full';
            this.magicSamloadingTakesLong = false;
        },
        finishLoadingMagicSam() {
            this.magicSamloadingState = null;
            this.magicSamloadingTakesLong = false;
        },
        toggleMagicSam() {
            // Activate Magic SAM.
            if (!this.isMagicSamming) {
                if (!this.canAdd) return;

                if (!magicSamInteraction) {
                    this.initMagicSamInteraction();
                }
                this.interactionMode = 'magicSam';
                return;
            }

            // Already active. Toggle detailed mode.
            if (!this.detailedModeActive) {
                if (!this.isLoadingDetailedMode) {
                    this.requestDetailedEmbedding();
                }
                return;
            }

            // For tiled images, only detailed mode is allowed.
            if (this.image.tiled) {
                this.resetInteractionMode();
            } else {
                this.exitDetailedMode();
            }
        },
        handleSamEmbeddingRequestSuccess(response) {
            if (this.image.id !== requestedImageId) {
                return;
            }

            if (this.isLoadingDetailedMode && response.body.bbox) {
                const extent = this.bboxToExtent(response.body.bbox);
                this.createExtentOverlay(extent);
            }

            if (response.body.url !== null) {
                this.handleSamEmbeddingAvailable(response.body);
            } else {
                // Wait for the Websockets event.
                this.magicSamloadingTakesLong = true;
                // Freeze interaction while loading long.
                magicSamInteraction.freeze();
            }
        },
        handleSamEmbeddingRequestFailure(response) {
            this.resetInteractionMode();
            this.finishLoadingMagicSam();
            handleErrorResponse(response);
        },
        handleSamEmbeddingAvailable(event) {
            if (this.image.id !== requestedImageId) {
                this.finishLoadingMagicSam();
                return;
            }

            magicSamInteraction.unfreeze();

            if (!this.isLoadingMagicSam) {
                return;
            }

            // Sometimes no detailed embedding is returned, e.g. if the requested
            // bbox is almost the full image so we have to check for the bbox.
            if (this.isLoadingDetailedMode && event.bbox) {
                this.handleDetailedEmbeddingAvailable(event);
            } else {
                this.handleFullEmbeddingAvailable(event);
            }
        },
        handleDetailedEmbeddingAvailable(event) {
            const extent = this.bboxToExtent(event.bbox);
            magicSamInteraction.setDetailedEmbedding(extent, event.url)
                .then(this.finishLoadingMagicSam)
                .then(() => {
                    this.detailedModeActive = true;
                    // The user could have disabled the interaction while loading.
                    if (this.isMagicSamming) {
                        magicSamInteraction.setActive(true);
                    }
                });
        },
        handleFullEmbeddingAvailable(event) {
            if (cachedFullImageId === this.image.id) {
                this.finishLoadingMagicSam();
                return;
            }

            cachedFullImageId = this.image.id;
            magicSamInteraction.updateEmbedding(this.image, event.url)
                .then(this.finishLoadingMagicSam)
                .then(() => {
                    // The user could have disabled the interaction while loading.
                    if (this.isMagicSamming) {
                        magicSamInteraction.setActive(true);
                    }
                });
        },
        handleSamEmbeddingFailed() {
            if (this.isLoadingMagicSam) {
                Messages.danger('Could not load the image embedding.');
                this.finishLoadingMagicSam();
                this.resetInteractionMode();
                this.exitDetailedMode();
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
                throttleInterval: this.magicSamThrottleInterval,
                modelInputSize: biigle.$require('magic-sam.model-input-size'),
            });
            magicSamInteraction.on('drawend', this.handleNewFeature);
            magicSamInteraction.setActive(false);
            this.map.addInteraction(magicSamInteraction);
        },
        clampExtentToImage(extent) {
            return [
                Math.round(Math.max(0, extent[0])),
                Math.round(Math.max(0, extent[1])),
                Math.round(Math.min(this.image.width, extent[2])),
                Math.round(Math.min(this.image.height, extent[3])),
            ];
        },
        requestDetailedEmbedding() {
            const viewportExtent = this.clampExtentToImage(
                this.map.getView().calculateExtent(this.map.getSize())
            );

            // By default, use the viewport extent to request the embedding.
            const bbox = {
                x: viewportExtent[0],
                y: viewportExtent[1],
                width: viewportExtent[2] - viewportExtent[0],
                height: viewportExtent[3] - viewportExtent[1],
            };

            const sketchExtent = magicSamInteraction.getSketchBoundingExtent();
            if (sketchExtent) {
                // Add padding and clamp to image bounds.
                const padding = this.detailedModeBboxPadding;
                const paddedExtent = this.clampExtentToImage([
                    sketchExtent[0] - padding,
                    sketchExtent[1] - padding,
                    sketchExtent[2] + padding,
                    sketchExtent[3] + padding,
                ]);

                // If the sketch feature is contained by the viewport extent, take its
                // extent for the embedding, instead.
                if (
                    paddedExtent[0] >= viewportExtent[0] &&
                    paddedExtent[1] >= viewportExtent[1] &&
                    paddedExtent[2] <= viewportExtent[2] &&
                    paddedExtent[3] <= viewportExtent[3]
                ) {
                    bbox.x = paddedExtent[0];
                    bbox.y = paddedExtent[1];
                    bbox.width = paddedExtent[2] - paddedExtent[0];
                    bbox.height = paddedExtent[3] - paddedExtent[1];
                }
            }

            // Convert OpenLayers y axis to backend coordinates.
            bbox.y = this.image.height - bbox.y - bbox.height;

            this.magicSamloadingState = 'loading-detailed';
            this.magicSamloadingTakesLong = false;
            ImageEmbeddingApi.save({id: this.image.id}, bbox)
                .then(
                    this.handleSamEmbeddingRequestSuccess,
                    (response) => {
                        this.finishLoadingMagicSam();
                        handleErrorResponse(response);
                    }
                );
        },
        exitDetailedMode() {
            this.removeDetailedOverlay();
            if (magicSamInteraction) {
                magicSamInteraction.clearDetailedEmbedding();
            }
            this.finishLoadingMagicSam();
            this.detailedModeActive = false;
        },
        initDetailedOverlayLayer() {
            if (!detailedOverlayLayer) {
                detailedOverlayLayer = new VectorLayer({
                    updateWhileAnimating: true,
                    updateWhileInteracting: true,
                    source: new VectorSource(),
                    map: this.map,
                    zIndex: OVERLAY_Z_INDEX,
                    style: new Style({
                        fill: new Fill({
                            color: `rgba(0, 0, 0, ${OVERLAY_OPACITY})`,
                        }),
                    }),
                });
            }
        },
        bboxToExtent(bbox) {
            // Convert bbox {x, y, width, height} (with y in image coords) to OL extent
            // [minX, minY, maxX, maxY] (with y in OL coords).
            const olY = this.image.height - bbox.y - bbox.height;
            return [bbox.x, olY, bbox.x + bbox.width, olY + bbox.height];
        },
        createExtentOverlay(extent) {
            this.initDetailedOverlayLayer();
            this.removeDetailedOverlay();

            // Full image polygon (outer ring) - counterclockwise for OpenLayers.
            const fullImage = [
                [0, 0],
                [this.image.width, 0],
                [this.image.width, this.image.height],
                [0, this.image.height],
                [0, 0],
            ];

            // Extent hole (inner ring) - clockwise for OpenLayers to create a hole.
            const extentHole = [
                [extent[0], extent[1]],
                [extent[0], extent[3]],
                [extent[2], extent[3]],
                [extent[2], extent[1]],
                [extent[0], extent[1]],
            ];

            detailedOverlayFeature = new Feature(new Polygon([fullImage, extentHole]));
            detailedOverlayLayer.getSource().addFeature(detailedOverlayFeature);

            // Create separate border feature for the hole.
            detailedBorderFeature = new Feature(new Polygon([extentHole]));
            detailedBorderFeature.setStyle(new Style({
                stroke: new Stroke({color: OVERLAY_BORDER_COLOR, width: OVERLAY_BORDER_WIDTH}),
            }));
            detailedOverlayLayer.getSource().addFeature(detailedBorderFeature);
        },
        removeDetailedOverlay() {
            if (!detailedOverlayLayer) {
                return;
            }
            if (detailedOverlayFeature) {
                detailedOverlayLayer.getSource().removeFeature(detailedOverlayFeature);
                detailedOverlayFeature = null;
            }
            if (detailedBorderFeature) {
                detailedOverlayLayer.getSource().removeFeature(detailedBorderFeature);
                detailedBorderFeature = null;
            }
        },
    },
    watch: {
        image(image) {
            if (this.isLoadingMagicSam && requestedImageId !== image.id) {
                this.finishLoadingMagicSam();
                this.resetInteractionMode();
            }

            if (this.detailedModeActive || this.isLoadingDetailedMode) {
                this.exitDetailedMode();
            }

            if (this.isMagicSamming) {
                this.resetInteractionMode();
            }
        },
        isMagicSamming(active) {
            if (!active) {
                this.exitDetailedMode();
                magicSamInteraction.setActive(false);
                return;
            }

            if (!this.hasSelectedLabel && !this.labelbotIsActive) {
                this.requireSelectedLabel();
                return;
            }

            if (cachedFullImageId === this.image.id) {
                magicSamInteraction.setActive(true);
                return;
            }

            if (this.isLoadingMagicSam) {
                return;
            }

            requestedImageId = this.image.id;

            // Tiled images only support detailed mode.
            if (this.image.tiled) {
                this.requestDetailedEmbedding();
                return;
            }

            this.startLoadingFullEmbedding();
            ImageEmbeddingApi.save({id: this.image.id}, {})
                .then(
                    this.handleSamEmbeddingRequestSuccess,
                    this.handleSamEmbeddingRequestFailure
                );
        },
        canAdd: {
            handler(canAdd) {
                if (canAdd) {
                    Keyboard.on('z', this.toggleMagicSam, 0, this.listenerSet);
                } else {
                    Keyboard.off('z', this.toggleMagicSam, 0, this.listenerSet);
                }
            },
            immediate: true,
        },
    },
    created() {
        Events.on('settings.samThrottleInterval', this.setThrottleInterval);
        Echo.getInstance().private(`user-${this.userId}`)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingAvailable', this.handleSamEmbeddingAvailable)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingFailed', this.handleSamEmbeddingFailed);
    },
};
</script>
