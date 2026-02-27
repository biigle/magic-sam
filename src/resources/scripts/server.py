from flask import Flask, request, send_file, g
import logging
import os
import threading
from tempfile import gettempdir
from microservice import process_requests, push_on_processing_queue
from process_request_thread import ProcessRequestThread

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
    ProcessRequestThread(process_requests).start()

try:
    start_request_processing()
    app = Flask(__name__)
except Exception as e:
    log_and_exit(e)


@app.route("/embedding", methods=['POST'])
def index():
    # files >500KB are saved in a tmp file and are not stored in memory
    image = request.files["image"]  # tmp file pointer
    filename = request.form['filename']
    out_path = "{t}/{f}".format(t=gettempdir(), f=filename)
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
        if path and os.path.exists(path):
            os.remove(path)
    except Exception as e:
        logging.warning("Couldn't delete file '{f}'".format(f=path))
