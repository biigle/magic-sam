import threading
import subprocess
import time

# monitors job listener thread
class JobListenerThread(threading.Thread):

    exception = None

    def listen_to_laravel_queue(self, cmd):
        try:
            cmd_list = cmd.split(' ')
            subprocess.run(cmd_list)
        except Exception as e:
            self.exception = e


    def run(self):
        artisan_cmd = 'php -d memory_limit=1G artisan queue:work --queue=gpu --sleep=5 --tries=3 --timeout=0'
        # elapsed time of listener thread in seconds
        elapsed_time = 5
        retry_count = 3
        # restart thread if possible
        while retry_count > 0:
            start = time.time()
            t = threading.Thread(target=self.listen_to_laravel_queue, daemon=True, args=[artisan_cmd])
            t.start()
            t.join()
            end = time.time()
            if ((end-start) < elapsed_time):
                retry_count -= 1
        raise BaseException("Failed to execute '{c}' due to:\n{e}\nShutting down the server.".format(c=artisan_cmd, e=self.exception))
