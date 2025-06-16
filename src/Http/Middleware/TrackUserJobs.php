<?php

namespace Biigle\Modules\MagicSam\Http\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;



class TrackUserJobs
{

    public function handle(Request $request, Closure $next): Response
    {
        $userCacheKey = sprintf(config('magic_sam.user_job_count'), $request->user()->id);
        $jobCount = Cache::get($userCacheKey, 0);

        if ($jobCount >= 1) {
            throw new TooManyRequestsHttpException("You already have a SAM job running. Please wait for the one to finish until you submit a new one.");
        }

        return $next($request);

    }

}