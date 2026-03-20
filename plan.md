+ implement generating of embeddings with pyworker similar to DINO worker of core

+ release, test in prod

(- implement real-time response mechanism when queue is not busy
   - only if I think it is relevant, otherwise we could save dependencies and required compute resources in the app container)

+ implement sam job rate limit

+ implement embedding refinement mechanism
    - update manual
    - make dedicated magic sam button now already with "detailed mode" sub control (e.g. looking glass)?
    - refinement UI/UX should work the same for tiled images

+ implement tiled image support
    - add case where bbox matches whole image. generate whole image embedding here
    - can't trust tile data of user/request (as done in Leanes implementation)

- maybe use the optimized crop loading also in core to generate largo thumbs?

- implement video support (now always creates a screenshot anyway because of labelbot?)
    - can't implement via screenshot because we can't trust user data
