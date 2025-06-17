from PIL import Image
from segment_anything import sam_model_registry
import numpy as np
import torch
import os
import requests
import time
from model import Model

MAGIC_SAM_MODEL_URL = 'MAGIC_SAM_MODEL_URL'
MAGIC_SAM_CHECKPOINT_PATH = 'MAGIC_SAM_CHECKPOINT_PATH'
MAGIC_SAM_MODEL_TYPE = 'MAGIC_SAM_MODEL_TYPE'
MAGIC_SAM_DEVICE = 'MAGIC_SAM_DEVICE'

url = os.environ.get(MAGIC_SAM_MODEL_URL)
path = os.environ.get(MAGIC_SAM_CHECKPOINT_PATH)
model_type = os.environ.get(MAGIC_SAM_MODEL_TYPE)
device = os.environ.get(MAGIC_SAM_DEVICE)

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
            "Timeout ({t}s): Checkpoint file is incomplete or corrupted.".format(t=timeout))

    return


def download_checkpoint(url, path, timeout=60, chunk_size_kb=1024):
    if os.path.exists(path):
        # check if checkpoint download is complete
        total_size = int(requests.head(url).headers['Content-Length'])
        size = os.path.getsize(path)
        if not size == total_size:
            wait_on_checkpoint_download(path, size, total_size, timeout)
        return

    dir, _ = os.path.split(path)
    chunk_size = chunk_size_kb * 1024  # default 1MB
    if not os.path.exists(dir):
        os.mkdir(dir)
    with requests.get(url, stream=True) as r:
        r.raise_for_status()
        with open(path, 'wb') as f:
            # copied from shutil.copyfileobj
            fsrc_read = r.raw.read
            fdst_write = f.write
            while True:
                buf = fsrc_read(chunk_size)
                if not buf:
                    break
                fdst_write(buf)


def check_sam_arguments():
    is_pth_file = path.endswith('.pth')
    if (not url or not is_pth_file or not model_type or not device):
        raise Exception("Couldn't initialize SAM model. Missing env-variables: {u}{p}{t}{d}".format(
            u=MAGIC_SAM_MODEL_URL+', ' if not url else '',
            p=MAGIC_SAM_CHECKPOINT_PATH+', ' if not is_pth_file else '',
            t=MAGIC_SAM_MODEL_TYPE+', ' if not model_type else '',
            d=MAGIC_SAM_DEVICE if not device else ''))

class Sam(metaclass=Model):
    def __init__(self):
        check_sam_arguments()
        download_checkpoint(url, path)
        self.device = device
        self.sam = sam_model_registry[model_type](checkpoint=path)
        self.sam.to(device=device)

    def generate_embedding(self, out_path, image):
        image = Image.open(image.stream)
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
            input_image_torch = torch.as_tensor(np.array(image), device=self.device)
            transformed_image = input_image_torch.permute(2, 0, 1).contiguous()[None, :, :, :]
            input_image = self.sam.preprocess(transformed_image)
            image_embedding = self.sam.image_encoder(input_image).cpu().numpy()

        np.save(out_path, image_embedding)
