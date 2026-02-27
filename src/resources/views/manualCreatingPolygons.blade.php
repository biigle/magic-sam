<h4><a name="magic-sam"></a><i class="fa fa-hat-wizard"></i> Magic SAM</h4>

<p>
    The magic SAM tool uses a <a href="https://segment-anything.com/">Segment Anything</a> computer vision model to automatically outline objects in images. The button to activate the magic SAM tool appears when you hover your cursor over the button of the polygon tool:
</p>

<p class="text-center">
    <a href="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_1.jpg')}}"><img src="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_1.jpg')}}" width="33%"></a>
</p>

<p>
    The computer vision model runs directly in your browser, so it may be faster or slower depending on the capabilities of your computer. When magic SAM is used on an image for the first time, an image embedding must be computed by BIIGLE. If this is the case, you will see a loading spinner in the button of the tool and little stars popping up. Computation of the image ebmedding can take a few seconds or even a minute. If the image embedding is already available, no stars will pop up and magic SAM will be ready sooner. When the loading animation is finished, magic SAM is ready to use.
</p>

<p>
    If magic SAM is active, all you need to do is to move your cursor over an object in the image and it will be automatically outlined. If the outline is not perfect, you can either try to move the cursor a bit or use the SAM focus modus by entering the key <td><kbd>y</kbd></td>. The focus modus is enabled by default for large images over {{ config('image.tiles.threshold') }}px. It uses the image section that is visible in the annotation tool to generate more detailed outlines. If the section is smaller than {{config('magic_sam.sam_target_size')}}px on at least one edge then a square of {{config('magic_sam.sam_target_size')}}x{{config('magic_sam.sam_target_size')}}px is used around the center of the image section. The image section displays transparency only in the segmentation area, with all other parts remaining opaque during this time. Return to the previous segmentation area and escape the focus modus by using the key <td><kbd>x</kbd></td>. Once you are satisfied with the outline, click and the current outline will be created as a polygon annotation. You can also use the <a href="/manual/tutorials/annotations/editing-annotations">eraser and fill tools</a> to modify the annotation later.
</p>

<p class="text-center">
    <a href="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_2.jpg')}}"><img src="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_2.jpg')}}" width="49%"></a>
    <a href="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_3.jpg')}}"><img src="{{asset('vendor/magic-sam/assets/creating_annotations_magic_sam_3.jpg')}}" width="49%"></a>
</p>
