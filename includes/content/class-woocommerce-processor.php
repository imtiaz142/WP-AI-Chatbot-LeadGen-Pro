<?php
/**
 * WooCommerce Processor.
 *
 * Extracts product data, descriptions, and metadata from WooCommerce products.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_WooCommerce_Processor {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @since 1.0.0
	 * @return bool True if WooCommerce is active, false otherwise.
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Process a single product.
	 *
	 * @since 1.0.0
	 * @param int   $product_id Product ID.
	 * @param array $args       Optional. Processing arguments.
	 * @return array|WP_Error Product data and content, or WP_Error on failure.
	 */
	public function process_product( $product_id, $args = array() ) {
		if ( ! $this->is_woocommerce_active() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not active.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$defaults = array(
			'include_variations' => true,
			'include_attributes' => true,
			'include_categories' => true,
			'include_tags'       => true,
			'include_reviews'    => false,
			'include_meta'       => true,
		);

		$args = wp_parse_args( $args, $defaults );

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				sprintf( __( 'Product with ID %d not found.', 'wp-ai-chatbot-leadgen-pro' ), $product_id )
			);
		}

		// Extract product data
		$product_data = $this->extract_product_data( $product, $args );

		// Format content for indexing
		$content = $this->format_product_content( $product_data, $args );

		return array(
			'product_id'   => $product_id,
			'product_data' => $product_data,
			'content'      => $content,
			'url'          => get_permalink( $product_id ),
			'word_count'   => str_word_count( $content ),
			'char_count'   => strlen( $content ),
		);
	}

	/**
	 * Extract product data.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @param array      $args    Processing arguments.
	 * @return array Product data.
	 */
	private function extract_product_data( $product, $args ) {
		$data = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'sku'               => $product->get_sku(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'manage_stock'      => $product->get_manage_stock(),
			'in_stock'          => $product->is_in_stock(),
			'weight'            => $product->get_weight(),
			'length'            => $product->get_length(),
			'width'             => $product->get_width(),
			'height'            => $product->get_height(),
			'rating_count'      => $product->get_rating_count(),
			'average_rating'    => $product->get_average_rating(),
			'review_count'      => $product->get_review_count(),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : null,
		);

		// Extract categories
		if ( $args['include_categories'] ) {
			$data['categories'] = $this->extract_categories( $product );
		}

		// Extract tags
		if ( $args['include_tags'] ) {
			$data['tags'] = $this->extract_tags( $product );
		}

		// Extract attributes
		if ( $args['include_attributes'] ) {
			$data['attributes'] = $this->extract_attributes( $product );
		}

		// Extract variations (for variable products)
		if ( $args['include_variations'] && $product->is_type( 'variable' ) ) {
			$data['variations'] = $this->extract_variations( $product );
		}

		// Extract custom meta
		if ( $args['include_meta'] ) {
			$data['meta'] = $this->extract_meta( $product );
		}

		// Extract reviews
		if ( $args['include_reviews'] ) {
			$data['reviews'] = $this->extract_reviews( $product );
		}

		// Extract related products
		$data['related_product_ids'] = $product->get_related_ids();

		// Extract upsell and cross-sell products
		$data['upsell_ids'] = $product->get_upsell_ids();
		$data['cross_sell_ids'] = $product->get_cross_sell_ids();

		// Extract product image URLs
		$data['image_url'] = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
		$data['gallery_image_urls'] = $this->extract_gallery_images( $product );

		return $data;
	}

	/**
	 * Extract product categories.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Categories data.
	 */
	private function extract_categories( $product ) {
		$categories = array();
		$term_ids = $product->get_category_ids();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = array(
					'id'          => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
				);
			}
		}

		return $categories;
	}

	/**
	 * Extract product tags.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Tags data.
	 */
	private function extract_tags( $product ) {
		$tags = array();
		$term_ids = $product->get_tag_ids();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$tags[] = array(
					'id'          => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
				);
			}
		}

		return $tags;
	}

	/**
	 * Extract product attributes.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Attributes data.
	 */
	private function extract_attributes( $product ) {
		$attributes = array();
		$product_attributes = $product->get_attributes();

		foreach ( $product_attributes as $attribute_name => $attribute ) {
			$attribute_data = array(
				'name'         => $attribute->get_name(),
				'id'           => $attribute->get_id(),
				'is_taxonomy'  => $attribute->is_taxonomy(),
				'is_visible'   => $attribute->get_visible(),
				'is_variation' => $attribute->get_variation(),
			);

			if ( $attribute->is_taxonomy() ) {
				// Taxonomy attribute
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'all' ) );
				$attribute_data['options'] = array_map( function( $term ) {
					return array(
						'id'   => $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}, $terms );
			} else {
				// Custom attribute
				$attribute_data['options'] = $attribute->get_options();
			}

			$attributes[] = $attribute_data;
		}

		return $attributes;
	}

	/**
	 * Extract product variations.
	 *
	 * @since 1.0.0
	 * @param WC_Product_Variable $product Variable product object.
	 * @return array Variations data.
	 */
	private function extract_variations( $product ) {
		$variations = array();
		$variation_ids = $product->get_children();

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$variation_data = array(
				'id'                => $variation->get_id(),
				'sku'               => $variation->get_sku(),
				'price'             => $variation->get_price(),
				'regular_price'     => $variation->get_regular_price(),
				'sale_price'        => $variation->get_sale_price(),
				'stock_status'      => $variation->get_stock_status(),
				'stock_quantity'    => $variation->get_stock_quantity(),
				'in_stock'          => $variation->is_in_stock(),
				'weight'            => $variation->get_weight(),
				'length'            => $variation->get_length(),
				'width'             => $variation->get_width(),
				'height'            => $variation->get_height(),
				'attributes'        => $variation->get_variation_attributes(),
				'image_url'         => wp_get_attachment_image_url( $variation->get_image_id(), 'full' ),
			);

			$variations[] = $variation_data;
		}

		return $variations;
	}

	/**
	 * Extract product meta.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Meta data.
	 */
	private function extract_meta( $product ) {
		$meta = array();
		$meta_data = get_post_meta( $product->get_id() );

		// Filter out internal WooCommerce meta
		$excluded_keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_product_version',
			'_product_attributes',
			'_default_attributes',
			'_product_image_gallery',
			'_thumbnail_id',
			'_wc_rating_count',
			'_wc_average_rating',
			'_wc_review_count',
		);

		foreach ( $meta_data as $key => $values ) {
			// Skip internal meta
			if ( strpos( $key, '_' ) === 0 && in_array( $key, $excluded_keys, true ) ) {
				continue;
			}

			// Skip serialized data that's not useful
			if ( is_serialized( $values[0] ) ) {
				continue;
			}

			$meta[ $key ] = maybe_unserialize( $values[0] );
		}

		return $meta;
	}

	/**
	 * Extract product reviews.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Reviews data.
	 */
	private function extract_reviews( $product ) {
		$reviews = array();
		$comments = get_comments( array(
			'post_id' => $product->get_id(),
			'status'  => 'approve',
			'type'    => 'review',
		) );

		foreach ( $comments as $comment ) {
			$rating = get_comment_meta( $comment->comment_ID, 'rating', true );

			$reviews[] = array(
				'id'          => $comment->comment_ID,
				'author'      => $comment->comment_author,
				'rating'      => $rating ? intval( $rating ) : null,
				'content'     => $comment->comment_content,
				'date'        => $comment->comment_date,
				'date_gmt'    => $comment->comment_date_gmt,
			);
		}

		return $reviews;
	}

	/**
	 * Extract gallery images.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Gallery image URLs.
	 */
	private function extract_gallery_images( $product ) {
		$gallery_image_ids = $product->get_gallery_image_ids();
		$gallery_urls = array();

		foreach ( $gallery_image_ids as $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $image_url ) {
				$gallery_urls[] = $image_url;
			}
		}

		return $gallery_urls;
	}

	/**
	 * Format product data as text content for indexing.
	 *
	 * @since 1.0.0
	 * @param array $product_data Product data.
	 * @param array $args         Processing arguments.
	 * @return string Formatted content.
	 */
	private function format_product_content( $product_data, $args ) {
		$content_parts = array();

		// Product name
		if ( ! empty( $product_data['name'] ) ) {
			$content_parts[] = 'Product: ' . $product_data['name'];
		}

		// SKU
		if ( ! empty( $product_data['sku'] ) ) {
			$content_parts[] = 'SKU: ' . $product_data['sku'];
		}

		// Short description
		if ( ! empty( $product_data['short_description'] ) ) {
			$content_parts[] = "\n" . $product_data['short_description'];
		}

		// Full description
		if ( ! empty( $product_data['description'] ) ) {
			$content_parts[] = "\n" . $product_data['description'];
		}

		// Price information
		if ( ! empty( $product_data['price'] ) ) {
			$price_text = 'Price: ' . wc_price( $product_data['price'] );
			if ( ! empty( $product_data['sale_price'] ) && $product_data['sale_price'] < $product_data['regular_price'] ) {
				$price_text .= ' (Regular: ' . wc_price( $product_data['regular_price'] ) . ', Sale: ' . wc_price( $product_data['sale_price'] ) . ')';
			}
			$content_parts[] = $price_text;
		}

		// Stock information
		if ( isset( $product_data['in_stock'] ) ) {
			$stock_text = 'Stock Status: ' . ( $product_data['in_stock'] ? 'In Stock' : 'Out of Stock' );
			if ( $product_data['manage_stock'] && isset( $product_data['stock_quantity'] ) ) {
				$stock_text .= ' (Quantity: ' . $product_data['stock_quantity'] . ')';
			}
			$content_parts[] = $stock_text;
		}

		// Categories
		if ( ! empty( $product_data['categories'] ) ) {
			$category_names = array_map( function( $cat ) {
				return $cat['name'];
			}, $product_data['categories'] );
			$content_parts[] = 'Categories: ' . implode( ', ', $category_names );
		}

		// Tags
		if ( ! empty( $product_data['tags'] ) ) {
			$tag_names = array_map( function( $tag ) {
				return $tag['name'];
			}, $product_data['tags'] );
			$content_parts[] = 'Tags: ' . implode( ', ', $tag_names );
		}

		// Attributes
		if ( ! empty( $product_data['attributes'] ) ) {
			$attributes_text = "\nAttributes:\n";
			foreach ( $product_data['attributes'] as $attribute ) {
				$options = is_array( $attribute['options'] ) 
					? array_map( function( $option ) {
						return is_array( $option ) ? $option['name'] : $option;
					}, $attribute['options'] )
					: array( $attribute['options'] );
				
				$attributes_text .= '- ' . $attribute['name'] . ': ' . implode( ', ', $options ) . "\n";
			}
			$content_parts[] = $attributes_text;
		}

		// Variations
		if ( ! empty( $product_data['variations'] ) ) {
			$variations_text = "\nVariations:\n";
			foreach ( $product_data['variations'] as $variation ) {
				$variation_text = '- Variation #' . $variation['id'];
				if ( ! empty( $variation['sku'] ) ) {
					$variation_text .= ' (SKU: ' . $variation['sku'] . ')';
				}
				if ( ! empty( $variation['price'] ) ) {
					$variation_text .= ' - Price: ' . wc_price( $variation['price'] );
				}
				if ( ! empty( $variation['attributes'] ) ) {
					$attr_text = array();
					foreach ( $variation['attributes'] as $attr_name => $attr_value ) {
						$attr_text[] = str_replace( 'attribute_', '', $attr_name ) . ': ' . $attr_value;
					}
					$variation_text .= ' (' . implode( ', ', $attr_text ) . ')';
				}
				$variations_text .= $variation_text . "\n";
			}
			$content_parts[] = $variations_text;
		}

		// Dimensions
		if ( ! empty( $product_data['weight'] ) || ! empty( $product_data['length'] ) ) {
			$dimensions = array();
			if ( ! empty( $product_data['weight'] ) ) {
				$dimensions[] = 'Weight: ' . $product_data['weight'] . ' ' . get_option( 'woocommerce_weight_unit' );
			}
			if ( ! empty( $product_data['length'] ) ) {
				$dimensions[] = 'Dimensions: ' . $product_data['length'] . ' × ' . $product_data['width'] . ' × ' . $product_data['height'] . ' ' . get_option( 'woocommerce_dimension_unit' );
			}
			$content_parts[] = implode( ', ', $dimensions );
		}

		// Reviews summary
		if ( ! empty( $product_data['average_rating'] ) ) {
			$content_parts[] = 'Rating: ' . $product_data['average_rating'] . ' out of 5 stars (' . $product_data['rating_count'] . ' reviews)';
		}

		// Reviews content
		if ( ! empty( $product_data['reviews'] ) ) {
			$reviews_text = "\nCustomer Reviews:\n";
			foreach ( $product_data['reviews'] as $review ) {
				$reviews_text .= '- ' . ( $review['rating'] ? $review['rating'] . ' stars: ' : '' ) . $review['content'] . "\n";
			}
			$content_parts[] = $reviews_text;
		}

		return implode( "\n\n", $content_parts );
	}

	/**
	 * Process all products.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Processing arguments.
	 * @return array Array of processed products.
	 */
	public function process_all_products( $args = array() ) {
		if ( ! $this->is_woocommerce_active() ) {
			return array();
		}

		$defaults = array(
			'status'   => 'publish',
			'limit'    => -1,
			'offset'   => 0,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => $args['status'],
			'posts_per_page' => $args['limit'],
			'offset'         => $args['offset'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'fields'         => 'ids',
		);

		$query = new WP_Query( $query_args );
		$products = array();

		foreach ( $query->posts as $product_id ) {
			$result = $this->process_product( $product_id, $args );
			if ( ! is_wp_error( $result ) ) {
				$products[] = $result;
			}
		}

		wp_reset_postdata();

		return $products;
	}
}

