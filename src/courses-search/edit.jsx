/**
 * Editor for the quick search block: ServerSideRender preview + settings panel
 * (placeholder, number of results, display options, color, open in a new tab).
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import {
	useBlockProps,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	ColorPalette,
	BaseControl,
	TextControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const DEFAULT_PRIMARY_COLOR = '#2a7be4';
const DEFAULT_PLACEHOLDER = 'Search for a course...';

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	return (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'Search Settings', 'kouib-blocks-for-tutor-lms' ) } initialOpen>
					<TextControl
						label={ __( 'Placeholder text', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.placeholder || '' }
						onChange={ ( v ) => setAttributes( { placeholder: v || DEFAULT_PLACEHOLDER } ) }
						help={ __( 'Leave empty to use the default text', 'kouib-blocks-for-tutor-lms' ) }
					/>
					<RangeControl
						label={ __( 'Search field width (px)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.fieldWidth || 460 }
						onChange={ ( v ) => setAttributes( { fieldWidth: v } ) }
						min={ 160 }
						max={ 1200 }
						step={ 20 }
					/>
					<SelectControl
						label={ __( 'Form alignment', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.formAlign || 'left' }
						options={ [
							{ label: __( 'Left', 'kouib-blocks-for-tutor-lms' ), value: 'left' },
							{ label: __( 'Center', 'kouib-blocks-for-tutor-lms' ), value: 'center' },
							{ label: __( 'Right', 'kouib-blocks-for-tutor-lms' ), value: 'right' },
						] }
						onChange={ ( v ) => setAttributes( { formAlign: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show results as an overlay', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.overlayResults }
						help={ __( 'When off, results appear inline and push content down instead of overlaying', 'kouib-blocks-for-tutor-lms' ) }
						onChange={ ( v ) => setAttributes( { overlayResults: v } ) }
					/>
					<RangeControl
						label={ __( 'Number of results shown', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.perPage }
						onChange={ ( v ) => setAttributes( { perPage: v } ) }
						min={ 1 }
						max={ 12 }
					/>
					<ToggleControl
						label={ __( 'Match exact phrase', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.fullPhrase }
						help={ __( 'When off: any single word is enough to match (ranked by relevance). When on: the phrase is matched exactly', 'kouib-blocks-for-tutor-lms' ) }
						onChange={ ( v ) => setAttributes( { fullPhrase: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show thumbnail', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showThumb }
						onChange={ ( v ) => setAttributes( { showThumb: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show price', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showPrice }
						onChange={ ( v ) => setAttributes( { showPrice: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show rating', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showRating }
						onChange={ ( v ) => setAttributes( { showRating: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show student count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showStudents }
						onChange={ ( v ) => setAttributes( { showStudents: v } ) }
					/>
					<ToggleControl
						label={ __( 'Open course in a new tab', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.openInNewTab }
						onChange={ ( v ) => setAttributes( { openInNewTab: v } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<BaseControl
						id="kouib-search-primary-color"
						label={ __( 'Primary color (focus border)', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.primaryColor }
							onChange={ ( v ) => setAttributes( { primaryColor: v || DEFAULT_PRIMARY_COLOR } ) }
						/>
					</BaseControl>

					<BaseControl
						id="kouib-search-field-bg"
						label={ __( 'Search field background', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.fieldBg || undefined }
							onChange={ ( v ) => setAttributes( { fieldBg: v || '' } ) }
						/>
					</BaseControl>

					<BaseControl
						id="kouib-search-field-border"
						label={ __( 'Search field border color', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.fieldBorder || undefined }
							onChange={ ( v ) => setAttributes( { fieldBorder: v || '' } ) }
						/>
					</BaseControl>

					<BaseControl
						id="kouib-search-field-text"
						label={ __( 'Search field text color', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.fieldText || undefined }
							onChange={ ( v ) => setAttributes( { fieldText: v || '' } ) }
						/>
					</BaseControl>

					<BaseControl
						id="kouib-search-icon-color"
						label={ __( 'Search icon color', 'kouib-blocks-for-tutor-lms' ) }
					>
						<ColorPalette
							value={ attributes.iconColor || undefined }
							onChange={ ( v ) => setAttributes( { iconColor: v || '' } ) }
						/>
					</BaseControl>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="kouib/courses-search"
					attributes={ attributes }
				/>
			</div>
		</Fragment>
	);
};