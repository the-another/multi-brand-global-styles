/**
 * Display-only canvas swap while a preview Brand is active. Image blocks get
 * an overridden display URL (attributes are never written back); the identity
 * blocks render lightweight substitutes. Deliberately degradable: anything
 * not covered falls back to its normal (default) rendering — the frontend
 * preview link is the fidelity backstop.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { STORE_NAME } from './store';

const withBrandPreviewCanvas = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const map = useSelect( ( select ) => {
			const brandId = select( STORE_NAME ).getPreviewBrandId();
			return brandId ? select( STORE_NAME ).getPreviewMap( brandId ) : null;
		}, [] );

		if ( ! map ) {
			return <BlockEdit { ...props } />;
		}

		if ( props.name === 'core/image' ) {
			const replacementUrl =
				props.attributes?.id && map.images
					? map.images[ props.attributes.id ]
					: null;
			if ( ! replacementUrl ) {
				return <BlockEdit { ...props } />;
			}
			return (
				<BlockEdit
					{ ...props }
					attributes={ { ...props.attributes, url: replacementUrl } }
				/>
			);
		}

		if ( props.name === 'core/site-logo' && map.logo_url ) {
			const width = props.attributes?.width;
			return (
				<div className="mbgs-preview-site-logo">
					<img
						src={ map.logo_url }
						alt=""
						style={ {
							maxWidth: width ? `${ width }px` : '120px',
							height: 'auto',
						} }
					/>
				</div>
			);
		}

		if ( props.name === 'core/site-title' && map.title ) {
			const level = props.attributes?.level ?? 1;
			const Tag = level === 0 ? 'p' : `h${ level }`;
			return <Tag className="wp-block-site-title">{ map.title }</Tag>;
		}

		if ( props.name === 'core/site-tagline' && map.tagline ) {
			return <p className="wp-block-site-tagline">{ map.tagline }</p>;
		}

		return <BlockEdit { ...props } />;
	},
	'withBrandPreviewCanvas'
);

addFilter( 'editor.BlockEdit', 'mbgs/brand-preview-canvas', withBrandPreviewCanvas );
