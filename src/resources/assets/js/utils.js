const tileSize = 256;

/**
 * 
 * Computes the tile group index for a zoomify tile with a given zoom, column and row index.
 * 
 * See https://github.com/openlayers/openlayers/blob/c6e3d24a08304d14d19852da35cef114a52008d7/src/ol/source/Zoomify.js#L221-L224
 * 
 * @param array totalTilesIndex 
 * @param int z The zoom level of the viewport
 * @param int x The tile's column index
 * @param int y The tile's row index 
 * @returns The tile group index
 */
let computeTileGroup = function (totalTilesIndex, z, x, y) {

    let tilesAtZ = totalTilesIndex[z];
    // Compute the continuous tile index
    let tileIndex = x + y * tilesAtZ[0];

    return (tileIndex + tilesAtZ[2]) / tileSize | 0;
}

/**
 * Compute the tile index array that contains the number of columns, rows and total number of tiles for each zoom level.
 * The values depend on the reduced image dimensions at each zoom level.
 * 
 * See https://github.com/openlayers/openlayers/blob/c6e3d24a08304d14d19852da35cef114a52008d7/src/ol/source/Zoomify.js#L221-L224
 * 
 * @param int widht 
 * @param int height 
 * @returns An array that is a combination of the openelayer's tierSizeInTiles and tileCountUpToTier array. 
 */
let computeTotalTilesIndex = function (widht, height) {
    // Compute log with base 0.5 by using base change
    // Ceil result because of discrete number of tiles per image
    let ceiled_log_0_5 = (x) => Math.ceil(Math.log(x) / Math.log(0.5));

    // Number of halving steps to obtain a reduced image with dimension WxH <= 256x256
    let nbrReductionStepts = Math.max(ceiled_log_0_5(tileSize / widht), ceiled_log_0_5(tileSize / height));

    let totalTiles_n = [];
    let totalTiles = 0;
    for (let i = nbrReductionStepts; i >= 0; i--) {
        let w_i = widht * Math.pow(0.5, i);
        let h_i = height * Math.pow(0.5, i);

        let nbrCols_i = Math.ceil(w_i / tileSize);
        let nbrRows_i = Math.ceil(h_i / tileSize);

        totalTiles_n[nbrReductionStepts - i] = [nbrCols_i, nbrRows_i, totalTiles];
        totalTiles += nbrCols_i * nbrRows_i;
    }
    return totalTiles_n;
}

export { computeTileGroup, computeTotalTilesIndex }