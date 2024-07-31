<component :is="plugins.magicSam" :settings="settings" inline-template>
    <div class="sidebar-tab__section">
            <h5 title="Refresh rate for updating Magic SAM proposals">Magic SAM refresh rate (<span v-text="stepName"></span>)</h5>
            <input id="magicSamRate" type="range" min="0" max="4" step="1" v-model="stepIndex" v-on:click='removeFocus("magicSamRate")'>
    </div>
</component>
