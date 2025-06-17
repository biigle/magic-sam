import logging
import threading
import queue
import os
from sam import Sam

request_queue = queue.Queue()
finished_tasks = dict()
threadCondition = threading.Condition()


class GenerateEmbeddingRequest():

    def __init__(self, out_path, image):
        self.out_path = out_path
        self.image = image

def process_requests():
    sam = Sam()
    while True:
        req = request_queue.get(block=True)
        try:
            sam.generate_embedding(req.out_path, req.image)
            with threadCondition:
                finished_tasks[req.out_path] = True
                threadCondition.notify_all()
        except Exception as e:
            logging.error("Failed to generate embedding for '{f}' due to:\n\t{e}".format(f=req.image.filename, e=e))
            with threadCondition:
                finished_tasks[req.out_path] = False
                threadCondition.notify_all()


def push_on_processing_queue(image, out_path):
    ge_request = GenerateEmbeddingRequest(out_path, image)
    request_queue.put_nowait(ge_request)
    with threadCondition:
        # wait until result or error was saved
        while not (out_path in finished_tasks):
            threadCondition.wait()

        # remove request identifier (out_path) from dict
        if finished_tasks[out_path]:
            del finished_tasks[out_path]

            return
        else:
            # remove request identifier (out_path) from dict
            del finished_tasks[out_path]

            # remove embedding file if it exists
            if os.path.exists(out_path):
                os.remove(out_path)

            return "Couldn't generate embedding", 400
