import {Resource} from '../import.js';

/**
 * Resource to request a SAM embedding.
 *
 * resource.save({id: 1}, {}).then(...);
 */
export default Resource('api/v1/images{/id}/sam-embedding', {}, {
    save: {
        url: 'api/v1/images{/id}/sam-embedding',
        method: 'post',
        responseType: 'blob'
    }
});
