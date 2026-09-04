/**
 * Registers the platform statistics block — reads its definition from block.json.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { Edit } from './edit';

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );