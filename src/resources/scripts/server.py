from flask import Flask, request
import logging
import threading
import sys
import base64
import os
from sam import Sam
from microservice import process_requests, generate_response

MAGIC_SAM_MODEL_URL = 'MAGIC_SAM_MODEL_URL'
MAGIC_SAM_CHECKPOINT_PATH = 'MAGIC_SAM_CHECKPOINT_PATH'
MAGIC_SAM_MODEL_TYPE = 'MAGIC_SAM_MODEL_TYPE'
MAGIC_SAM_DEVICE = 'MAGIC_SAM_DEVICE'

url = os.environ.get(MAGIC_SAM_MODEL_URL)
path = os.environ.get(MAGIC_SAM_CHECKPOINT_PATH)
model_type = os.environ.get(MAGIC_SAM_MODEL_TYPE)
device = os.environ.get(MAGIC_SAM_DEVICE)


def is_checkpoint_path():
    return path.endswith('.pth')


try:
    if (not url or not is_checkpoint_path() or not model_type or not device):
        raise Exception("Couldn't initialize python server.\n\tMissing env-variables: {u}{p}{t}{d}".format(
            u=MAGIC_SAM_MODEL_URL+', ' if not url else '',
            p=MAGIC_SAM_CHECKPOINT_PATH+', ' if not is_checkpoint_path() else '',
            t=MAGIC_SAM_MODEL_TYPE+', ' if not model_type else '',
            d=MAGIC_SAM_DEVICE if not device else ''))
    sam = Sam(url, path, model_type, device)
    threading.Thread(target=process_requests, daemon=True, args=[sam]).start()
    app = Flask(__name__)
except Exception as e:
    logging.error(e)
    sys.exit(4)


@app.route("/embedding", methods=['POST'])
def index():
    args = request.get_json()
    image = base64.b64decode(args['image'])
    out_path = args['out_path']

    return generate_response(image, out_path)
