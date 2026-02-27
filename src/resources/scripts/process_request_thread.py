import threading
import time
import logging

class ProcessRequestThread(threading.Thread):

    # needed in new exception hook
    exception = None

    retry_count = None

    target = None

    def __init__(self, target, retry_count = 3):
        self.retry_count = retry_count
        self.target = target
        super().__init__()


    def run(self):
        # elapsed time of listener thread in seconds
        elapsed_time = 5
        # restart thread if possible
        while self.retry_count > 0:
            start = time.time()
            self.target()
            end = time.time()
            if ((end-start) < elapsed_time):
                self.retry_count -= 1
                logging.warning("Restarting the request processing thread...")
        raise BaseException("Failed to process requests due to:\n{e}\nShutting down the server.".format(e=self.exception))
