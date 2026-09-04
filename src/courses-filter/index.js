/**
 * Registers the block — reads its full definition from block.json (the modern standard).
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { Edit } from './edit';

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
