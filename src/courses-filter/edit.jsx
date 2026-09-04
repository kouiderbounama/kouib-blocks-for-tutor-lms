/**
 * Editor component: settings panel + ServerSideRender preview.
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
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

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	return (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'General Settings', 'kouib-blocks-for-tutor-lms' ) } initialOpen>
					<RangeControl
						label={ __( 'Courses per category', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.perPage }
						onChange={ ( v ) => setAttributes( { perPage: v } ) }
						min={ 1 }
						max={ 12 }
					/>
					<RangeControl
						label={ __( 'Number of columns', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columns }
						onChange={ ( v ) => setAttributes( { columns: v } ) }
						min={ 1 }
						max={ 4 }
					/>
					<SelectControl
						label={ __( 'Course order', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.orderby }
						options={ [
							{ label: __( 'Newest', 'kouib-blocks-for-tutor-lms' ), value: 'date' },
							{ label: __( 'Oldest', 'kouib-blocks-for-tutor-lms' ), value: 'date_asc' },
							{ label: __( 'Title (A-Z)', 'kouib-blocks-for-tutor-lms' ), value: 'title' },
							{ label: __( 'Random', 'kouib-blocks-for-tutor-lms' ), value: 'rand' },
							{ label: __( 'Most students', 'kouib-blocks-for-tutor-lms' ), value: 'students' },
						] }
						onChange={ ( v ) => setAttributes( { orderby: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show the "All" button', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showAll }
						onChange={ ( v ) => setAttributes( { showAll: v } ) }
					/>
					<ToggleControl
						label={ __( 'Open course in a new tab', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.openInNewTab }
						onChange={ ( v ) => setAttributes( { openInNewTab: v } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Show / Hide Elements', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Level', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showLevel }
						onChange={ ( v ) => setAttributes( { showLevel: v } ) }
					/>
					<ToggleControl
						label={ __( 'Rating', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showRating }
						onChange={ ( v ) => setAttributes( { showRating: v } ) }
					/>
					<ToggleControl
						label={ __( 'Lesson count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showLessons }
						onChange={ ( v ) => setAttributes( { showLessons: v } ) }
					/>
					<ToggleControl
						label={ __( 'Duration', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showDuration }
						onChange={ ( v ) => setAttributes( { showDuration: v } ) }
					/>
					<ToggleControl
						label={ __( 'Price', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showPrice }
						onChange={ ( v ) => setAttributes( { showPrice: v } ) }
					/>
					<ToggleControl
						label={ __( 'Student count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showStudents }
						onChange={ ( v ) => setAttributes( { showStudents: v } ) }
					/>
					<ToggleControl
						label={ __( 'Enroll button', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showEnrollBtn }
						onChange={ ( v ) => setAttributes( { showEnrollBtn: v } ) }
					/>
					<TextControl
						label={ __( 'Enroll button text', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.enrollBtnText || '' }
						onChange={ ( v ) => setAttributes( { enrollBtnText: v } ) }
						help={ __( 'Leave empty to use the default text', 'kouib-blocks-for-tutor-lms' ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Card shadow', 'kouib-blocks-for-tutor-lms' ) }
						help={ __( 'Turn off for flat cards with no shadow', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.hasShadow }
						onChange={ ( v ) => setAttributes( { hasShadow: v } ) }
					/>
					<BaseControl
						id="kouib-primary-color"
						label={ __( 'Buttons and hover color', 'kouib-blocks-for-tutor-lms' ) }
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
					block="kouib/courses-filter"
					attributes={ attributes }
				/>
			</div>
		</Fragment>
	);
};
