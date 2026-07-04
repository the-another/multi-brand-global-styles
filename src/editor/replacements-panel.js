/**
 * "Brand replacements" panel on the core Image block. A UI over the central
 * per-Brand image map: every change saves immediately via mbgs/v1 REST
 * (replacements are Brand data, not post content) — no block attributes are
 * ever written.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import { PanelBody, Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

function ReplacementRow( { row, attachmentId, onSaved } ) {
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const save = ( replacementId ) => {
		setIsSaving( true );
		setError( null );
		apiFetch( {
			path: '/mbgs/v1/replacements',
			method: 'POST',
			data: {
				brand_id: row.brand_id,
				original_id: attachmentId,
				replacement_id: replacementId,
			},
		} )
			.then( onSaved )
			.catch( () =>
				setError( __( 'Saving failed.', 'the-another-multi-brand-global-styles' ) )
			)
			.finally( () => setIsSaving( false ) );
	};

	return (
		<div style={ { marginBottom: '16px' } }>
			<strong>{ row.brand_name }</strong>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ isSaving ? (
				<Spinner />
			) : (
				<MediaUploadCheck>
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						value={ row.replacement_id || undefined }
						onSelect={ ( media ) => save( media.id ) }
						render={ ( { open } ) =>
							row.replacement_id ? (
								<div>
									<img
										src={ row.replacement_thumb_url }
										alt=""
										style={ { maxWidth: '100%', display: 'block' } }
									/>
									<Button variant="secondary" onClick={ open }>
										{ __( 'Change', 'the-another-multi-brand-global-styles' ) }
									</Button>
									<Button
										variant="link"
										isDestructive
										onClick={ () => save( null ) }
									>
										{ __( 'Remove', 'the-another-multi-brand-global-styles' ) }
									</Button>
								</div>
							) : (
								<Button variant="secondary" onClick={ open }>
									{ __( 'Set replacement', 'the-another-multi-brand-global-styles' ) }
								</Button>
							)
						}
					/>
				</MediaUploadCheck>
			) }
		</div>
	);
}

function BrandReplacementsPanel( { attachmentId } ) {
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let ignore = false;
		setRows( null );
		setError( null );
		apiFetch( { path: `/mbgs/v1/replacements?original=${ attachmentId }` } )
			.then( ( result ) => {
				if ( ! ignore ) {
					setRows( result );
				}
			} )
			.catch( () => {
				if ( ! ignore ) {
					setError(
						__(
							'Could not load Brand replacements.',
							'the-another-multi-brand-global-styles'
						)
					);
				}
			} );
		return () => {
			ignore = true;
		};
	}, [ attachmentId ] );

	return (
		<PanelBody
			title={ __( 'Brand replacements', 'the-another-multi-brand-global-styles' ) }
			initialOpen={ false }
		>
			{ rows === null && ! error && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ rows !== null && rows.length === 0 && (
				<p>{ __( 'No published Brands.', 'the-another-multi-brand-global-styles' ) }</p>
			) }
			{ rows !== null &&
				rows.map( ( row ) => (
					<ReplacementRow
						key={ row.brand_id }
						row={ row }
						attachmentId={ attachmentId }
						onSaved={ ( updated ) =>
							setRows( ( current ) =>
								current.map( ( r ) =>
									r.brand_id === updated.brand_id ? updated : r
								)
							)
						}
					/>
				) ) }
		</PanelBody>
	);
}

const withBrandReplacements = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		if (
			props.name !== 'core/image' ||
			! props.attributes?.id ||
			! props.isSelected
		) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				<BlockEdit { ...props } />
				<InspectorControls>
					<BrandReplacementsPanel attachmentId={ props.attributes.id } />
				</InspectorControls>
			</>
		);
	},
	'withBrandReplacements'
);

addFilter(
	'editor.BlockEdit',
	'mbgs/brand-replacements-panel',
	withBrandReplacements
);
