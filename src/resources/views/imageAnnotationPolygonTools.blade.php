<control-button v-if="image?.tiled" icon="fa-hat-wizard" title="The magic SAM tool is not available for very large images" :disabled="true"></control-button>
<control-button v-else v-cloak icon="fa-hat-wizard" :title="magicSamButtonTitle" :active="isMagicSamming" :loading="loadingMagicSam" :class="magicSamButtonClass" v-on:click="toggleMagicSam"></control-button>

@push('scripts')
    {{vite_hot(base_path('vendor/biigle/magic-sam/hot'), ['src/resources/assets/js/main.js'], 'vendor/magic-sam')}}
<script type="module">
    biigle.$declare('magic-sam.onnx-url', '{{cachebust_asset('vendor/magic-sam/'.config('magic_sam.onnx_file'))}}');
</script>
@endpush

@push('styles')
{{vite_hot(base_path('vendor/biigle/magic-sam/hot'), ['src/resources/assets/sass/main.scss'], 'vendor/magic-sam')}}
@endpush
