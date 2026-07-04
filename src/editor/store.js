/**
 * Editor-side store for the Brand preview state. Selection is per-user,
 * preview-only, and never persisted — it resets with the editor session.
 */
import { createReduxStore, register } from '@wordpress/data';

export const STORE_NAME = 'mbgs/editor';

const DEFAULT_STATE = {
	previewBrandId: 0,
	previewMaps: {},
};

const store = createReduxStore( STORE_NAME, {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'SET_PREVIEW_BRAND':
				return { ...state, previewBrandId: action.brandId };
			case 'RECEIVE_PREVIEW_MAP':
				return {
					...state,
					previewMaps: {
						...state.previewMaps,
						[ action.brandId ]: action.map,
					},
				};
			default:
				return state;
		}
	},
	actions: {
		setPreviewBrand: ( brandId ) => ( {
			type: 'SET_PREVIEW_BRAND',
			brandId,
		} ),
		receivePreviewMap: ( brandId, map ) => ( {
			type: 'RECEIVE_PREVIEW_MAP',
			brandId,
			map,
		} ),
	},
	selectors: {
		getPreviewBrandId: ( state ) => state.previewBrandId,
		getPreviewMap: ( state, brandId ) =>
			state.previewMaps[ brandId ] || null,
	},
} );

register( store );
