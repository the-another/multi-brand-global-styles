/**
 * "Brand" editor sidebar: pick a Brand to preview the canvas as (images +
 * identity blocks, display-only), plus a full-fidelity frontend preview
 * link (?mbgs_preview_brand resolver override — admin-only, per-request).
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { PanelBody, RadioControl, Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from './store';

function BrandSidebar() {
	const [ brands, setBrands ] = useState( [] );

	useEffect( () => {
		apiFetch( { path: '/mbgs/v1/brands' } )
			.then( setBrands )
			.catch( () => setBrands( [] ) );
	}, [] );

	const previewBrandId = useSelect(
		( select ) => select( STORE_NAME ).getPreviewBrandId(),
		[]
	);
	const { setPreviewBrand, receivePreviewMap } = useDispatch( STORE_NAME );

	const postPreviewLink = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return editor && editor.getEditedPostPreviewLink
			? editor.getEditedPostPreviewLink()
			: null;
	}, [] );
	const baseUrl =
		postPreviewLink ||
		( window.mbgsEditor && window.mbgsEditor.homeUrl ) ||
		'/';

	const choose = ( value ) => {
		const brandId = Number( value );
		setPreviewBrand( brandId );
		if ( brandId ) {
			apiFetch( {
				path: addQueryArgs( '/mbgs/v1/preview-map', { brand: brandId } ),
			} )
				.then( ( map ) => receivePreviewMap( brandId, map ) )
				.catch( () => {} );
		}
	};

	return (
		<>
			<PluginSidebarMoreMenuItem target="mbgs-brand">
				{ __( 'Brand', 'the-another-multi-brand-global-styles' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="mbgs-brand"
				title={ __( 'Brand', 'the-another-multi-brand-global-styles' ) }
				icon="admin-multisite"
			>
				<PanelBody>
					<RadioControl
						label={ __( 'Preview as', 'the-another-multi-brand-global-styles' ) }
						selected={ String( previewBrandId ) }
						options={ [
							{
								label: __( 'Default', 'the-another-multi-brand-global-styles' ),
								value: '0',
							},
							...brands.map( ( brand ) => ( {
								label: brand.brand_name,
								value: String( brand.brand_id ),
							} ) ),
						] }
						onChange={ choose }
					/>
					{ previewBrandId !== 0 && (
						<Button
							variant="secondary"
							href={ addQueryArgs( baseUrl, {
								mbgs_preview_brand: previewBrandId,
							} ) }
							target="_blank"
							rel="noopener"
						>
							{ __(
								'Open frontend preview as this Brand',
								'the-another-multi-brand-global-styles'
							) }
						</Button>
					) }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'mbgs-brand-preview', { render: BrandSidebar } );
