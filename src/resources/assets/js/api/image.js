/**
 * Resource to request a SAM embedding.
 *
 * resource.save({id: 1}, {}).then(...);
 *
 * @type {Vue.resource}
 */
export default Vue.resource('api/v1/images{/id}/sam-embedding', {}, {
    save: {
        url: 'api/v1/images{/id}/sam-embedding',
        method: 'post',
        responseType: 'blob'
    }
});
