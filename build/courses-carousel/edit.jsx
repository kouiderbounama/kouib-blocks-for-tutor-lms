/**
 * Carousel editor component: full settings panel + ServerSideRender preview.
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	ColorPalette,
	BaseControl,
	TextControl,
	Spinner,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const DEFAULT_PRIMARY_COLOR = '#2a7be4';

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	const categories = useSelect( ( select ) => {
		return select( 'core' ).getEntityRecords( 'taxonomy', 'course-category', {
			hide_empty: true,
			per_page: -1,
		} );
	}, [] );

	return (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'Courses Settings', 'kouib-blocks-for-tutor-lms' ) } initialOpen>
					<BaseControl
						id="kouib-carousel-categories"
						label={ __( 'Categories to show', 'kouib-blocks-for-tutor-lms' ) }
					>
						{ categories ? (
							<SelectControl
								multiple
								value={ ( attributes.categories || [] ).map( String ) }
								options={ categories.map( ( c ) => ( {
									label: c.name,
									value: String( c.id ),
								} ) ) }
								onChange={ ( vals ) => setAttributes( {
									categories: ( vals || [] ).map( Number ),
								} ) }
							/>
						) : (
							<Spinner />
						) }
					</BaseControl>
					<p
						style={ { margin: '0 0 16px', color: '#757575', fontSize: 12 } }
						className="components-base-control__help"
					>
						{ __( 'Leave empty to show courses from all categories', 'kouib-blocks-for-tutor-lms' ) }
					</p>
					<RangeControl
						label={ __( 'Number of courses shown', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.coursesToShow }
						onChange={ ( v ) => setAttributes( { coursesToShow: v } ) }
						min={ 1 }
						max={ 24 }
					/>
					<RangeControl
						label={ __( 'Columns (desktop)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.columns }
						onChange={ ( v ) => setAttributes( { columns: v } ) }
						min={ 1 }
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
				</PanelBody>

				<PanelBody title={ __( 'Motion Settings', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Autoplay', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.autoplay }
						onChange={ ( v ) => setAttributes( { autoplay: v } ) }
					/>
					{ attributes.autoplay && (
						<RangeControl
							label={ __( 'Autoplay speed (ms)', 'kouib-blocks-for-tutor-lms' ) }
							value={ attributes.autoplaySpeed }
							onChange={ ( v ) => setAttributes( { autoplaySpeed: v } ) }
							min={ 1000 }
							max={ 10000 }
							step={ 500 }
						/>
					) }
					<RangeControl
						label={ __( 'Slide speed (ms)', 'kouib-blocks-for-tutor-lms' ) }
						value={ attributes.speed }
						onChange={ ( v ) => setAttributes( { speed: v } ) }
						min={ 100 }
						max={ 2000 }
						step={ 100 }
					/>
					<ToggleControl
						label={ __( 'Infinite loop', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.infiniteLoop }
						onChange={ ( v ) => setAttributes( { infiniteLoop: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show navigation arrows', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showArrows }
						onChange={ ( v ) => setAttributes( { showArrows: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show dots', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.showDots }
						onChange={ ( v ) => setAttributes( { showDots: v } ) }
					/>
					<ToggleControl
						label={ __( 'Pause on hover', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.pauseOnHover }
						onChange={ ( v ) => setAttributes( { pauseOnHover: v } ) }
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
					{ attributes.showEnrollBtn && (
						<TextControl
							label={ __( 'Enroll button text', 'kouib-blocks-for-tutor-lms' ) }
							value={ attributes.enrollBtnText || '' }
							onChange={ ( v ) => setAttributes( { enrollBtnText: v } ) }
							help={ __( 'Leave empty to use the default text', 'kouib-blocks-for-tutor-lms' ) }
						/>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'kouib-blocks-for-tutor-lms' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Card shadow', 'kouib-blocks-for-tutor-lms' ) }
						help={ __( 'Turn off for flat cards with no shadow', 'kouib-blocks-for-tutor-lms' ) }
						checked={ attributes.hasShadow }
						onChange={ ( v ) => setAttributes( { hasShadow: v } ) }
					/>
					<BaseControl
						id="kouib-carousel-primary-color"
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
					block="kouib/courses-carousel"
					attributes={ attributes }
				/>
			</div>
		</Fragment>
	);
};