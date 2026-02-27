from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from PIL import Image, ImageFile
from queue import Queue, Empty
from segment_anything import sam_model_registry
from threading import Thread, Event
from torch import device, no_grad, as_tensor
from torch.cuda import is_available as cuda_is_available
import fcntl
import io
import numpy as np
import os
import signal
import sys
import time
import traceback
import urllib.request

class RequestItem:
    def __init__(self, data, event):
        self.data = data
        self.event = event
        self.result = None  # Placeholder for the result
        self.exception = None

request_queue = Queue()
shutdown_event = Event()

def maybe_download_checkpoint(url, path):
    """Downloads the model checkpoint if it doesn't exist yet.

    Uses file locking to prevent multiple processes from downloading simultaneously.
    Other processes will wait for the lock to be released.
    """
    dir_path = os.path.dirname(path)
    if not os.path.exists(dir_path):
        os.makedirs(dir_path, mode=0o700, exist_ok=True)

    lock_path = path + '.lock'

    # Use lockfile so there is no parallel writing to the same file or the later
    # process assumes the file is complete although it is still downloading.
    with open(lock_path, 'w') as lock_file:
        print(f'Acquiring lock for checkpoint download...', file=sys.stderr)
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)

        try:
            # Check again if file exists (another process might have downloaded it)
            if not os.path.exists(path):
                print(f'Downloading checkpoint from {url} to {path}...', file=sys.stderr)
                try:
                    urllib.request.urlretrieve(url, path)
                    print(f'Checkpoint downloaded successfully.', file=sys.stderr)
                except Exception as e:
                    raise Exception(f"Failed to download checkpoint from '{url}': {e}")
            else:
                print(f'Checkpoint already exists at {path}, skipping download.', file=sys.stderr)
        finally:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
            print(f'Lock released.', file=sys.stderr)

class RequestHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)

        event = Event()
        request_item = RequestItem(io.BytesIO(post_data), event)
        request_queue.put(request_item)

        # Wait for the worker to process this request
        event.wait()

        if request_item.exception is None:
            # Access the result set by the worker
            result = request_item.result
            # Send the result back to the client as binary .npy data
            self.send_response(200)
            self.send_header('Content-type', 'application/octet-stream')
            self.end_headers()
            self.wfile.write(result)
        else:
            traceback.print_exception(request_item.exception)
            tb = traceback.TracebackException.from_exception(request_item.exception)
            self.send_response(500)
            self.send_header('Content-type', 'text/plain')
            self.end_headers()
            self.wfile.write(''.join(tb.format()).encode())

def worker():
    # See: https://stackoverflow.com/a/23575424/1796523
    ImageFile.LOAD_TRUNCATED_IMAGES = True

    model_type = os.environ.get('SAM_MODEL_TYPE')
    model_url = os.environ.get('SAM_MODEL_URL')

    # The storage directory is shared by all workers so the checkpoint is only downloaded
    # once.
    CHECKPOINT_PATH = 'storage/magic_sam/sam_checkpoint.pth'

    dev = device('cuda' if cuda_is_available() else 'cpu')

    maybe_download_checkpoint(model_url, CHECKPOINT_PATH)
    # Load SAM model
    sam = sam_model_registry[model_type](checkpoint=CHECKPOINT_PATH)
    sam.to(device=dev)

    while not shutdown_event.is_set():
        try:
            # Use timeout to periodically check shutdown_event
            request_item = request_queue.get(timeout=1)
        except Empty:
            # Timeout occurred, continue to check shutdown_event
            continue

        try:
            # Open image and convert to RGB
            image = Image.open(request_item.data)

            # Process with SAM
            with no_grad():
                input_image_torch = as_tensor(np.array(image), device=dev)
                transformed_image = input_image_torch.permute(2, 0, 1).contiguous()[None, :, :, :]
                input_image = sam.preprocess(transformed_image)
                image_embedding = sam.image_encoder(input_image).cpu().numpy()

            # Serialize to .npy format in memory
            buffer = io.BytesIO()
            np.save(buffer, image_embedding)
            request_item.result = buffer.getvalue()
        except Exception as e:
            request_item.exception = e

        # Signal that the request has been processed
        request_item.event.set()
        request_queue.task_done()

if __name__ == '__main__':
    httpd = ThreadingHTTPServer(('', 80), RequestHandler)

    def signal_handler(signum, frame):
        shutdown_event.set()
        # shutdown() must be called from a different thread than serve_forever()
        Thread(target=httpd.shutdown).start()

    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    worker_thread = Thread(target=worker, daemon=False)
    worker_thread.start()

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        print('Shutting down HTTP server...', file=sys.stderr)
        shutdown_event.set()
        httpd.shutdown()
        httpd.server_close()

        worker_thread.join(timeout=5)

        print('Shutdown complete.', file=sys.stderr)
        sys.exit(0)
