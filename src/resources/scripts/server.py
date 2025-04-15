from flask import Flask, request
import logging
import threading
import sys
import base64
import os
from sam import Sam
from microservice import process_requests, generate_response

url = os.environ.get('MAGIC_SAM_MODEL_URL')
path = os.environ.get("CHECKPOINT_PATH")
model_type = os.environ.get('MAGIC_SAM_MODEL_TYPE')
device = os.environ.get('MAGIC_SAM_DEVICE')

try:
    if (not url or not '.pth' in path or not model_type or not device):
        # überarbeiten
        raise Exception("Magic-Sam environment variables are missing.")
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
