/**
 * Editor for the platform statistics block: real preview via ServerSideRender
 * + a control panel (show/hide each statistic, grid, size, style, color).
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
	ToggleControl,
	ColorPalette,
	BaseControl,
	SelectControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const DEFAULT_PRIMARY_COLOR = '#2a7be4';

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	return (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'Statistics', 'kouib-blocks-for-tutor-lms' ) } initialOpen>
					<ToggleControl
						label={ __( 'Student count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.showStudents }
						onChange={ ( v ) => setAttributes( { showStudents: v } ) }
					/>
					<ToggleControl
						label={ __( 'Instructor count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.showInstructors }
						onChange={ ( v ) => setAttributes( { showInstructors: v } ) }
					/>
					<ToggleControl
						label={ __( 'Course count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.showCourses }
						onChange={ ( v ) => setAttributes( { showCourses: v } ) }
					/>
					<ToggleControl
						label={ __( 'Lesson count', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.showLessons }
						onChange={ ( v ) => setAttributes( { showLessons: v } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Grid', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<RangeControl
						label={ __( 'Columns (desktop)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columns }
						onChange={ ( v ) => setAttributes( { columns: v } ) }
						min={ 2 }
						max={ 4 }
					/>
					<RangeControl
						label={ __( 'Columns (tablet)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columnsTablet }
						onChange={ ( v ) => setAttributes( { columnsTablet: v } ) }
						min={ 1 }
						max={ 4 }
					/>
					<RangeControl
						label={ __( 'Columns (mobile)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columnsMobile }
						onChange={ ( v ) => setAttributes( { columnsMobile: v } ) }
						min={ 1 }
						max={ 2 }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Icons & Labels', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Show stat labels', 'kouib-blocks-for-tutor-lms' ) }
						checked={ !! attributes.showLabels }
						onChange={ ( v ) => setAttributes( { showLabels: v } ) }
					/>
					<SelectControl
						label={ __( 'Icon style', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.iconStyle }
						options={ [
							{ label: __( 'Outline (lines)', 'kouib-blocks-for-tutor-lms' ), value: 'outline' },
							{ label: __( 'Filled', 'kouib-blocks-for-tutor-lms' ), value: 'filled' },
						] }
						onChange={ ( v ) => setAttributes( { iconStyle: v } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<BaseControl
						id="kouib-stats-primary-color"
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
					block="kouib/courses-stats"
					attributes={ attributes }
				/>
			</div>
		</Fragment>
	);
};