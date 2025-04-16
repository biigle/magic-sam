from PIL import Image
from segment_anything import sam_model_registry
from segment_anything.utils.transforms import ResizeLongestSide
import numpy as np
import torch
import os
import requests
from shutil import copyfileobj
import time


def wait_on_checkpoint_download(path, size, total_size, timeout):
    elapsed_time = 0
    sleep_time = 3
    download_failed = False
    # check for completion every 3 seconds
    while not size == total_size:
        if elapsed_time >= timeout:
            download_failed = True
            break
        size = os.path.getsize(path)
        elapsed_time += sleep_time
        time.sleep(sleep_time)

    if download_failed:
        raise TimeoutError(
            "Timeout ({t}s): Checkpoint file incomplete or corrupted.".format(t=timeout))

    return


def download_checkpoint(url, path, timeout=60):
    if os.path.exists(path):
        # check if checkpoint download is complete
        total_size = int(requests.head(url).headers['Content-Length'])
        size = os.path.getsize(path)
        if not size == total_size:
            wait_on_checkpoint_download(path, size, total_size, timeout)
        return

    dir, _ = os.path.split(path)
    if not os.path.exists(dir):
        os.mkdir(dir)
    with requests.get(url, stream=True) as r:
        r.raise_for_status()
        with open(path, 'wb') as f:
            copyfileobj(r.raw, f)


class Sam():
    def __init__(self, checkpoint_url, checkpoint_path, model_type, device):
        download_checkpoint(checkpoint_url, checkpoint_path)
        self.device = device
        self.sam = sam_model_registry[model_type](checkpoint=checkpoint_path)
        self.sam.to(device=device)  # does it take long?

    def generate_embedding(self, out_path, img_path, thread):
        transform = ResizeLongestSide(self.sam.image_encoder.img_size)
        thread.join()
        image = Image.open(img_path)
        if image.mode in ['RGBA', 'L', 'P', 'CMYK']:
            image = image.convert('RGB')
        elif image.mode in ['I', 'I;16']:
            # I images (32 bit signed integer) and I;16 (16 bit unsigned imteger)
            # need to be rescaled manually before converting.
            # image/256 === image/(2**16)*(2**8)
            image = Image.fromarray(
                (np.array(image)/256).astype(np.uint8)).convert('RGB')

        if image.mode != 'RGB':
            raise ValueError(f'Only RGB images supported, was {image.mode}')

        with torch.no_grad():
            input_image = transform.apply_image(np.array(image))
            input_image_torch = torch.as_tensor(
                input_image, device=self.device)
            transformed_image = input_image_torch.permute(
                2, 0, 1).contiguous()[None, :, :, :]
            input_image = self.sam.preprocess(transformed_image)
            image_embedding = self.sam.image_encoder(input_image).cpu().numpy()

        np.save(out_path, image_embedding)
