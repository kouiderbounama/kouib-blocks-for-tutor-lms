/**
 * Editor for the categories grid block: column grid + ServerSideRender preview +
 * upload a custom icon image for each category from the media library (replace/remove).
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	useBlockProps,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	ToggleControl,
	ColorPalette,
	BaseControl,
	Button,
	Spinner,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const DEFAULT_PRIMARY_COLOR = '#2a7be4';

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	const terms = useSelect( ( select ) => {
		return select( 'core' ).getEntityRecords( 'taxonomy', 'course-category', {
			hide_empty: !! attributes.hideEmpty,
			per_page: -1,
		} );
	}, [ attributes.hideEmpty ] );

	const icons = Array.isArray( attributes.icons ) ? attributes.icons : [];

	const iconFor = ( termId ) => {
		const found = icons.find( ( i ) => Number( i.termId ) === Number( termId ) );
		return found && found.url ? found.url : '';
	};

	const setTermIcon = ( termId, media ) => {
		if ( media && 'image/svg+xml' === media.mime ) {
			return;
		}
		const updated = icons.filter( ( i ) => Number( i.termId ) !== Number( termId ) );
		if ( media && media.url ) {
			updated.push( { termId: Number( termId ), id: media.id || 0, url: media.url } );
		}
		setAttributes( { icons: updated } );
	};

	const removeTermIcon = ( termId ) => {
		setAttributes( { icons: icons.filter( ( i ) => Number( i.termId ) !== Number( termId ) ) } );
	};

	return (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'Grid Settings', 'kouib-blocks-for-tutor-lms' ) } initialOpen>
					<RangeControl
						label={ __( 'Columns (desktop)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columns }
						onChange={ ( v ) => setAttributes( { columns: v } ) }
						min={ 1 }
						max={ 6 }
					/>
					<RangeControl
						label={ __( 'Columns (tablet)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columnsTablet }
						onChange={ ( v ) => setAttributes( { columnsTablet: v } ) }
						min={ 1 }
						max={ 6 }
					/>
					<RangeControl
						label={ __( 'Columns (mobile)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columnsMobile }
						onChange={ ( v ) => setAttributes( { columnsMobile: v } ) }
						min={ 1 }
						max={ 6 }
					/>
					<ToggleControl
						label={ __( 'Show course count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showCount }
						onChange={ ( v ) => setAttributes( { showCount: v } ) }
					/>
					<ToggleControl
						label={ __( 'Hide empty categories', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.hideEmpty }
						help={ __( 'When off, categories show even with no courses', 'kouib-blocks-for-tutor-lms' ) }
						onChange={ ( v ) => setAttributes( { hideEmpty: v } ) }
					/>
					<ToggleControl
						label={ __( 'Open category link in a new tab', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.openInNewTab }
						onChange={ ( v ) => setAttributes( { openInNewTab: v } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Category icons', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<p
						style={ { margin: '0 0 16px', color: '#757575', fontSize: 12 } }
						className="components-base-control__help"
					>
						{ __( 'Upload a PNG image for each category; it appears above the category name inside the box and can be replaced or removed anytime', 'kouib-blocks-for-tutor-lms' ) }
					</p>
					{ terms ? (
						terms.map( ( term ) => {
							const iconUrl = iconFor( term.id );
							return (
								<div
									key={ term.id }
									style={ { display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 } }
								>
									{ iconUrl ? (
										<img
											src={ iconUrl }
											alt=""
											style={ { width: 36, height: 36, objectFit: 'contain', borderRadius: 6, flex: '0 0 36px' } }
										/>
									) : (
										<span
											style={ { width: 36, height: 36, borderRadius: 6, background: '#e5e7eb', flex: '0 0 36px' } }
										/>
									) }
									<span style={ { flex: '1 1 auto', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
										{ term.name }
									</span>
									<MediaUploadCheck>
										<MediaUpload
											onSelect={ ( media ) => setTermIcon( term.id, media ) }
											allowedTypes={ [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ] }
											render={ ( { open } ) => (
												<Button variant="secondary" size="small" onClick={ open }>
													{ iconUrl
														? __( 'Replace', 'kouib-blocks-for-tutor-lms' )
														: __( 'Add icon', 'kouib-blocks-for-tutor-lms' ) }
												</Button>
											) }
										/>
									</MediaUploadCheck>
									{ iconUrl && (
										<Button
											variant="secondary"
											size="small"
											isDestructive
											onClick={ () => removeTermIcon( term.id ) }
										>
											{ __( 'Remove', 'kouib-blocks-for-tutor-lms' ) }
										</Button>
									) }
								</div>
							);
						} )
					) : (
						<Spinner />
					) }
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<BaseControl
						id="kouib-cat-primary-color"
						label={ __( 'Primary color', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.primaryColor }
							onChange={ ( v ) => setAttributes( { primaryColor: v || DEFAULT_PRIMARY_COLOR } ) }
						/>
					</BaseControl>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="kouib/courses-categories"
					attributes={ attributes }
				/>
			</div>
		</Fragment>
	);
};