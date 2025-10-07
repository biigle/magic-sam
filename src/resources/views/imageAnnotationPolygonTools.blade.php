<control-button
    v-cloak
    icon="fa-hat-wizard"
    :title="magicSamButtonTitle"
    :active="isMagicSamming"
    :loading="loadingMagicSam"
    :class="magicSamButtonClass"
    v-on:click="toggleMagicSam"
    v-on:active="onActive"
    ></control-button>

@push('scripts')
    {{vite_hot(base_path('vendor/biigle/magic-sam/hot'), ['src/resources/assets/js/main.js'], 'vendor/magic-sam')}}
<script type="module">
    biigle.$declare('magic-sam.onnx-url', '{{cachebust_asset('vendor/magic-sam/assets/'.config('magic_sam.onnx_file'))}}');
    biigle.$declare('magic-sam.sam_target_size', {{config('magic_sam.sam_target_size')}});
</script>
@endpush

@push('styles')
{{vite_hot(base_path('vendor/biigle/magic-sam/hot'), ['src/resources/assets/sass/main.scss'], 'vendor/magic-sam')}}
@endpush
