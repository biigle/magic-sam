<script>
import ImageEmbeddingApi from './api/image.js';
import MagicSamInteraction from './ol/MagicSamInteraction.js';
import {Echo} from './import.js';
import {handleErrorResponse} from './import.js';
import {Keyboard} from './import.js';
import {Messages} from './import.js';
import {Styles} from './import.js';
import {Events} from './import.js';

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
            detailedModeActive: false,
            loadingDetailedMode: false,
            detailedModeExtentPadding: 100,
        };
    },
    computed: {
        isMagicSamming() {
            return this.interactionMode === 'magicSam';
        },
        magicSamButtonClass() {
            return {
                'loading-magic-sam-long': this.loadingMagicSamTakesLong,
                'control-button--success': this.detailedModeActive,
            };
        },
        magicSamButtonTitle() {
            if (this.loadingMagicSamTakesLong) {
                return 'Preparing the magic for this image';
            }

            if (this.loadingDetailedMode) {
                return 'Loading detailed mode...';
            }

            if (this.detailedModeActive) {
                return 'Detailed mode active (press 𝗭 to exit)';
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
            this.loadingDetailedMode = false;
        },
        toggleMagicSam() {
            if (this.isMagicSamming) {
                if (this.detailedModeActive) {
                    this.exitDetailedMode();
                } else if (!this.loadingDetailedMode) {
                    this.requestDetailedEmbedding();
                }
            } else if (this.canAdd && !this.image.tiled) {
                if (!magicSamInteraction) {
                    this.initMagicSamInteraction();
                }
                this.interactionMode = 'magicSam';
            }
        },
        handleSamEmbeddingRequestSuccess(response) {
            if (this.image.id !== loadingImageId) {
                return;
            }

            if (response.body.url !== null) {
                this.handleSamEmbeddingAvailable(response.body);
            } else {
                // Wait for the Websockets event.
                this.loadingMagicSamTakesLong = true;
            }
        },
        handleSamEmbeddingRequestFailure(response) {
            this.resetInteractionMode();
            this.finishLoadingMagicSam();
            handleErrorResponse(response);
        },
        handleSamEmbeddingAvailable(event) {
            if (this.image.id !== loadingImageId) {
                this.finishLoadingMagicSam();
                return;
            }

            if (this.loadingDetailedMode) {
                if (event.extent) {
                    // Convert y axis back to Openlayers coordinates.
                    event.extent.y = this.image.height - event.extent.y - event.extent.height;
                    magicSamInteraction.setDetailedEmbedding(event.extent, event.url)
                        .then(this.finishLoadingMagicSam)
                        .then(() => this.detailedModeActive = true);
                } else {
                    // Sometimes no detailed embedding is returned, e.g. if the requested
                    // extent is almost the full image.
                    this.finishLoadingMagicSam();
                }

                return;
            }

            // Regular full-image embedding
            if (!this.loadingMagicSam) {
                return;
            }

            if (loadedImageId === this.image.id) {
                this.finishLoadingMagicSam();
                return;
            }

            loadedImageId = this.image.id;
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
                modelInputSize: biigle.$require('magic-sam.model-input-size'),
            });
            magicSamInteraction.on('drawend', this.handleNewFeature);
            magicSamInteraction.setActive(false);
            this.map.addInteraction(magicSamInteraction);
        },
        requestDetailedEmbedding() {
            const viewportExtent = this.map.getView().calculateExtent(this.map.getSize());

            // Clamp viewport to image bounds
            viewportExtent[0] = Math.round(Math.max(0, viewportExtent[0]));
            viewportExtent[1] = Math.round(Math.max(0, viewportExtent[1]));
            viewportExtent[2] = Math.round(Math.min(this.image.width, viewportExtent[2]));
            viewportExtent[3] = Math.round(Math.min(this.image.height, viewportExtent[3]));
            // By default, use the viewport extent to request the embedding.
            const bbox = {
                x: viewportExtent[0],
                y: viewportExtent[1],
                width: viewportExtent[2] - viewportExtent[0],
                height: viewportExtent[3] - viewportExtent[1],
            };

            const sketchExtent = magicSamInteraction.getSketchBoundingExtent()?.slice();
            if (sketchExtent) {
                // Add padding and clamp to image bounds.
                const padding = this.detailedModeExtentPadding;
                sketchExtent[0] = Math.round(Math.max(0, sketchExtent[0] - padding));
                sketchExtent[1] = Math.round(Math.max(0, sketchExtent[1] - padding));
                sketchExtent[2] = Math.round(Math.min(this.image.width, sketchExtent[2] + padding));
                sketchExtent[3] = Math.round(Math.min(this.image.height, sketchExtent[3] + padding));

                // If the sketch feature is contained by the viewport extent, take its
                // extent for the embedding, instead.
                if (
                    sketchExtent[0] >= viewportExtent[0] &&
                    sketchExtent[1] >= viewportExtent[1] &&
                    sketchExtent[2] <= viewportExtent[2] &&
                    sketchExtent[3] <= viewportExtent[3]
                ) {
                    bbox.x = sketchExtent[0];
                    bbox.y = sketchExtent[1];
                    bbox.width = sketchExtent[2] - sketchExtent[0];
                    bbox.height = sketchExtent[3] - sketchExtent[1];
                }
            }

            // Convert OpenLayers y axis to backend coordinates.
            bbox.y = this.image.height - bbox.y - bbox.height;

            this.loadingDetailedMode = true;
            this.loadingMagicSam = true;
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
            if (magicSamInteraction) {
                magicSamInteraction.clearDetailedEmbedding();
            }
            this.finishLoadingMagicSam();
            this.detailedModeActive = false;
        },
    },
    watch: {
        image(image) {
            if (this.loadingMagicSam && loadingImageId !== image.id) {
                this.finishLoadingMagicSam();
                this.resetInteractionMode();
            }

            if (this.detailedModeActive || this.loadingDetailedMode) {
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

            if (loadedImageId === this.image.id) {
                magicSamInteraction.setActive(true);
                return;
            }

            if (this.loadingMagicSam) {
                return;
            }

            loadingImageId = this.image.id;
            this.startLoadingMagicSam();
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
