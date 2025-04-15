import logging
import threading
import queue
import base64
import os
import tempfile

request_queue = queue.Queue()
finished_tasks = dict()
threadCondition = threading.Condition()

class GenerateEmbeddingRequest():

    def __init__(self, out_path, img_path, t):
        self.out_path = out_path
        self.image_path = img_path
        self.thread = t

def saveImageAsFile(content, path):
    try:
        f = open(path, 'wb')
        f.write(content)
        f.close()
    except Exception as e:
        # somehow exit request
        logging.error(e)

def process_requests(sam):
    while True:
        req = request_queue.get(block=True)
        try:
            sam.generate_embedding(req.out_path, req.image_path, req.thread)
            with threadCondition:
                finished_tasks[req.out_path] = True
                threadCondition.notify_all()
        except Exception as e:
            logging.error("Failed to generate embedding for '{f}' due to:\n\t{e}".format(f=req.out_path,e=e))
            with threadCondition:
                finished_tasks[req.out_path] = False
                threadCondition.notify_all()


def generate_response(image, out_path):

    # save image temporarely to reduce memory usage during waiting
    path = "{d}/{i}.binary".format(d=tempfile.gettempdir(), i=os.path.splitext(os.path.basename(out_path))[0])
    t = threading.Thread(target=saveImageAsFile, daemon=True, args=[image, path])
    t.start()
    del image

    ge_request = GenerateEmbeddingRequest(out_path, path, t)
    request_queue.put_nowait(ge_request)
    with threadCondition:
        # wait until result or error was saved
        while not (out_path in finished_tasks):
            threadCondition.wait()

        # remove request identifier (out_path) from dict
        if finished_tasks[out_path]:
            del finished_tasks[out_path]

            # load and encode embedding
            embedding = open(out_path, 'rb').read()
            data = base64.b64encode(embedding)
            data = data.decode('utf-8')
            
            # remove embedding file
            if os.path.exists(out_path):
                os.remove(out_path)
            if os.path.exists(path):
                os.remove(path)
            return {'data': data}
        else:
            # remove request identifier (out_path) from dict
            del finished_tasks[out_path]

            # remove embedding file if it exists
            if os.path.exists(out_path):
                os.remove(out_path)
            if os.path.exists(path):
                os.remove(path)

        return {'data': {}}