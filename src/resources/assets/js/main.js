import './settingsTabPlugins.js';
import MagicSamMixin from './MagicSamMixin.vue';
import {annotationCanvasMixins} from './import.js';
import {env} from "onnxruntime-web";

annotationCanvasMixins.push(MagicSamMixin);
// This path is configured for the publish command in the MagicSamServiceProvider.
env.wasm.wasmPaths = '/vendor/magic-sam/';
