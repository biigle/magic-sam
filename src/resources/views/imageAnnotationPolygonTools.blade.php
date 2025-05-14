<control-button v-cloak icon="fa-hat-wizard" :title="magicSamButtonTitle" :active="isMagicSamming" :loading="loadingMagicSam" :class="magicSamButtonClass" v-on:click="toggleMagicSam"></control-button>

@push('scripts')
<script src="{{ cachebust_asset('vendor/magic-sam/scripts/main.js') }}"></script>
<script type="text/javascript">
    biigle.$declare('magic-sam.onnx-url', '{{cachebust_asset('vendor/magic-sam/'.config('magic_sam.onnx_file'))}}');
    biigle.$declare('magic-sam.sam_target_size', {{config('magic_sam.sam_target_size')}});
</script>
@endpush

@push('styles')
<link rel="stylesheet" type="text/css" href="{{ cachebust_asset('vendor/magic-sam/styles/main.css') }}">
@endpush
