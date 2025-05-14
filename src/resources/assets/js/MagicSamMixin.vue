<script>
import ImageEmbeddingApi from './api/image';
import MagicSamInteraction from './ol/MagicSamInteraction';
import {Echo} from './import';
import {handleErrorResponse} from './import';
import {Keyboard} from './import';
import {Messages} from './import';
import {Styles} from './import';
import {Events} from './import';

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
        viewportExtent() {
            let viewport = [...this.viewExtent];
            // invert y-axis to start from low to high
            this.invertPointsYAxis(viewport);
            return this.validateExtent(viewport);
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
        handleSamEmbeddingRequestSuccess(responseBody) {
            if (this.image.id !== loadingImageId) {
                return;
            }

            if (responseBody.embedding !== null) {
                this.embeddingId = responseBody.id
                this.handleSamEmbeddingAvailable(responseBody);
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
        handleSamEmbeddingAvailable(response) {
            if (!this.loadingMagicSam) {
                return;
            }

            if (this.image.id !== loadingImageId) {
                return;
            }

            if (loadedImageId === this.image.id) {
                return;
            }

            let bufferedEmbedding = null;
            let usedExtent = null;
            let url = null;

            if (response.embedding) {
                // Decode and write to arrayBuffer
                bufferedEmbedding = Buffer.from(response.embedding, 'base64').buffer
            } else {
                url = response.url
            }

            usedExtent = response.extent;
            this.invertPointsYAxis(usedExtent);

            loadedImageId = this.image.id;
            magicSamInteraction.updateEmbedding(url, bufferedEmbedding, usedExtent)
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
            magicSamInteraction.on('drawend', this.handleNewFeature);
            magicSamInteraction.setActive(false);
            this.map.addInteraction(magicSamInteraction);
        },
        validateExtent(extent) {
            // Set viewport values on 0 if the viewport corners are located outside the image
            let viewport = extent.map((c, i) => {
                if (i % 2 == 0) {
                    return c < 0 ? 0 : c > this.image.width ? this.image.width : c
                } else {
                    return c < 0 ? 0 : c > this.image.height ? this.image.height : c
                }
            });

            // Resize images which are smaller than the target size on at least on edge
            let width = viewport[2] - viewport[0];
            let height = viewport[1] - viewport[3];

            if (width < this.targetSize || height < this.targetSize) {
                let diffX = (this.targetSize - width) / 2;
                let diffY = (this.targetSize - height) / 2;

                viewport[0] -= diffX
                viewport[2] += diffX

                viewport[1] += diffY
                viewport[3] -= diffY
            }

            return viewport;
        },
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
            this.startLoadingMagicSam();

            ImageEmbeddingApi.save({ id: this.image.id }, { extent: this.viewportExtent })
                .then((res) => this.handleSamEmbeddingRequestSuccess(res.body),
                    this.handleSamEmbeddingRequestFailure
                ).catch(handleErrorResponse);
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
        this.targetSize = biigle.$require('magic-sam.sam_target_size');
        Events.$on('settings.samThrottleInterval', this.setThrottleInterval);
        Echo.getInstance().private(`user-${this.userId}`)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingAvailable', this.handleSamEmbeddingAvailable)
            .listen('.Biigle\\Modules\\MagicSam\\Events\\EmbeddingFailed', this.handleSamEmbeddingFailed);
    },
};
</script>
