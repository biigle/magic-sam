from flask import Flask, request, send_file, g
import logging
import os
from sam import Sam
import threading
from microservice import process_requests, push_on_processing_queue
from process_request_thread import ProcessRequestThread

MAGIC_SAM_MODEL_URL = 'MAGIC_SAM_MODEL_URL'
MAGIC_SAM_CHECKPOINT_PATH = 'MAGIC_SAM_CHECKPOINT_PATH'
MAGIC_SAM_MODEL_TYPE = 'MAGIC_SAM_MODEL_TYPE'
MAGIC_SAM_DEVICE = 'MAGIC_SAM_DEVICE'

url = os.environ.get(MAGIC_SAM_MODEL_URL)
path = os.environ.get(MAGIC_SAM_CHECKPOINT_PATH)
model_type = os.environ.get(MAGIC_SAM_MODEL_TYPE)
device = os.environ.get(MAGIC_SAM_DEVICE)


def log_and_exit(e):
    logging.error(e)
    # terminate all threads and worker
    os._exit(4)


def excepthook(args):
    log_and_exit(args.exc_value)


def start_request_processing():
    # override excepthook because server should be stopped
    # if process_request thread cannot be restarted
    threading.excepthook = excepthook
    ProcessRequestThread(sam, process_requests).start()


def is_checkpoint_path():
    return path.endswith('.pth')


def check_sam_arguments():
    if (not url or not is_checkpoint_path() or not model_type or not device):
        raise Exception("Couldn't initialize python server. Missing env-variables: {u}{p}{t}{d}".format(
            u=MAGIC_SAM_MODEL_URL+', ' if not url else '',
            p=MAGIC_SAM_CHECKPOINT_PATH+', ' if not is_checkpoint_path() else '',
            t=MAGIC_SAM_MODEL_TYPE+', ' if not model_type else '',
            d=MAGIC_SAM_DEVICE if not device else ''))


try:
    check_sam_arguments()
    sam = Sam(url, path, model_type, device)
    start_request_processing()
    app = Flask(__name__)
except Exception as e:
    log_and_exit(e)


@app.route("/embedding", methods=['POST'])
def index():
    # files >500KB are saved in a tmp file and are not stored in memory
    image = request.files["image"]  # tmp file pointer
    out_path = request.form['out_path']
    push_on_processing_queue(image, out_path)
    g.tmp_file = out_path
    return send_file(out_path, 'application/octet-stream', True, out_path)

# delete file even when unhandled exception was thrown
@app.teardown_appcontext
def finalize_request(exception):
    if exception:
        logging.exception(exception)

    # remove tmp file of current request after it was sent successfully
    try:
        path = g.pop('tmp_file', None)
        if os.path.exists(path):
            os.remove(path)
    except Exception as e:
        logging.error("Couldn't delete file '{f}'".format(f=path))
