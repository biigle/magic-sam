<template>
    <div class="sidebar-tab__section">
        <h5 title="Refresh rate for updating Magic SAM proposals">Magic SAM refresh rate (<span v-text="stepName"></span>)</h5>
        <input type="range" min="0" max="4" step="1" v-model="stepIndex" onmouseup="this.blur()">
    </div>
</template>
<script>
import {Events} from '../import.js';
/**
 * The plugin component to edit the Magic-SAM throttle interval.
 *
 * @type {Object}
 */
export default {
    props: {
        settings: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            steps: [2000, 1000, 500, 200, 100],
            stepNames: ['slower', 'slow', 'medium', 'fast', 'faster'],
            stepIndex: 2,
        };
    },
    computed: {
        stepName() {
            return this.stepNames[this.stepIndex];
        },
    },
    watch: {
        stepIndex(index) {
            let interval = this.steps[index];
            Events.emit('settings.samThrottleInterval', interval);
            this.settings.set('samRefreshRateStep', index);
        },
    },
    created() {
        if (this.settings.has('samRefreshRateStep')) {
            this.stepIndex = this.settings.get('samRefreshRateStep');
        }
    },
};
</script>
