<?php
/**
 * Contextual Questions.
 *
 * Generates contextual quick-start questions based on current page content.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Contextual_Questions {

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
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $provider_factory;

	/**
	 * Default questions per page type.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $page_type_questions = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->provider_factory = new WP_AI_Chatbot_LeadGen_Pro_Provider_Factory();

		$this->init_default_questions();
	}

	/**
	 * Initialize default questions for page types.
	 *
	 * @since 1.0.0
	 */
	private function init_default_questions() {
		$this->page_type_questions = array(
			'home' => array(
				__( 'What do you offer?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How can you help me?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Tell me about your services', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What makes you different?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'product' => array(
				__( 'Tell me more about this product', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What are the key features?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Is this right for my needs?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Are there any alternatives?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'pricing' => array(
				__( 'What plans do you offer?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Is there a free trial?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s included in each plan?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Do you offer discounts?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'contact' => array(
				__( 'What\'s the fastest way to reach you?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I schedule a call?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What are your business hours?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Where are you located?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'support' => array(
				__( 'I need help with an issue', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Where can I find documentation?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How do I get started?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can you troubleshoot my problem?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'blog' => array(
				__( 'Tell me more about this topic', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Do you have related articles?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How does this apply to my situation?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I get more details?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'category' => array(
				__( 'What products are in this category?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Help me choose the right product', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s your best seller?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Compare options for me', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'checkout' => array(
				__( 'Do you have a discount code?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What payment methods do you accept?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s your return policy?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How long is shipping?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'faq' => array(
				__( 'I have a question not listed here', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can you explain this further?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'I need more specific help', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'about' => array(
				__( 'Tell me about your company', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s your mission?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How long have you been in business?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Who founded the company?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'demo' => array(
				__( 'How long is the trial?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s included in the demo?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I get a personalized walkthrough?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What happens after the trial?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'service' => array(
				__( 'Tell me more about this service', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'How does the process work?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What results can I expect?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can you customize this for me?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'default' => array(
				__( 'How can you help me?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Tell me more about this', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'I have a question', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I speak to someone?', 'wp-ai-chatbot-leadgen-pro' ),
			),
		);
	}

	/**
	 * Get contextual questions for current page.
	 *
	 * @since 1.0.0
	 * @param array $context Page context.
	 * @return array Questions array.
	 */
	public function get_questions( $context = array() ) {
		$defaults = array(
			'page_type'    => '',
			'page_url'     => '',
			'page_title'   => '',
			'page_content' => '',
			'post_id'      => 0,
			'product_id'   => 0,
			'max_questions' => 4,
			'use_ai'       => false,
		);
		$context = wp_parse_args( $context, $defaults );

		// Auto-detect page type if not provided
		if ( empty( $context['page_type'] ) ) {
			$context['page_type'] = $this->detect_page_type( $context );
		}

		// Get base questions for page type
		$questions = $this->get_page_type_questions( $context['page_type'] );

		// Add content-specific questions
		$content_questions = $this->get_content_specific_questions( $context );
		if ( ! empty( $content_questions ) ) {
			$questions = array_merge( $content_questions, $questions );
		}

		// Use AI to generate questions if enabled
		if ( $context['use_ai'] && ! empty( $context['page_content'] ) ) {
			$ai_questions = $this->generate_ai_questions( $context );
			if ( ! empty( $ai_questions ) ) {
				$questions = array_merge( $ai_questions, $questions );
			}
		}

		// Remove duplicates and limit
		$questions = array_unique( $questions );
		$questions = array_slice( $questions, 0, $context['max_questions'] );

		return array_values( $questions );
	}

	/**
	 * Detect page type from context.
	 *
	 * @since 1.0.0
	 * @param array $context Page context.
	 * @return string Page type.
	 */
	private function detect_page_type( $context ) {
		// Check URL patterns
		$url = strtolower( $context['page_url'] );

		$url_patterns = array(
			'pricing'  => array( '/pricing', '/plans', '/packages' ),
			'contact'  => array( '/contact', '/get-in-touch' ),
			'support'  => array( '/support', '/help', '/faq' ),
			'faq'      => array( '/faq', '/frequently-asked' ),
			'about'    => array( '/about', '/about-us' ),
			'blog'     => array( '/blog', '/news', '/articles' ),
			'demo'     => array( '/demo', '/trial', '/free-trial' ),
			'checkout' => array( '/checkout', '/cart', '/basket' ),
			'product'  => array( '/product/', '/shop/' ),
			'service'  => array( '/service', '/solution' ),
		);

		foreach ( $url_patterns as $type => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( strpos( $url, $pattern ) !== false ) {
					return $type;
				}
			}
		}

		// Check WordPress conditions
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return 'category';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'checkout';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		if ( is_single() ) {
			return 'blog';
		}

		if ( is_category() || is_tag() || is_archive() ) {
			return 'category';
		}

		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		if ( is_page() ) {
			$slug = get_post_field( 'post_name', get_the_ID() );
			return $this->detect_page_type_from_slug( $slug );
		}

		return 'default';
	}

	/**
	 * Detect page type from slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Page slug.
	 * @return string Page type.
	 */
	private function detect_page_type_from_slug( $slug ) {
		$slug_lower = strtolower( $slug );

		$slug_types = array(
			'pricing'  => array( 'pricing', 'plans', 'packages', 'price' ),
			'contact'  => array( 'contact', 'contact-us', 'get-in-touch' ),
			'support'  => array( 'support', 'help', 'help-center' ),
			'faq'      => array( 'faq', 'faqs', 'frequently-asked-questions' ),
			'about'    => array( 'about', 'about-us', 'our-story', 'our-team' ),
			'blog'     => array( 'blog', 'news', 'articles', 'resources' ),
			'demo'     => array( 'demo', 'request-demo', 'trial', 'free-trial' ),
			'service'  => array( 'services', 'solutions', 'offerings' ),
		);

		foreach ( $slug_types as $type => $slugs ) {
			if ( in_array( $slug_lower, $slugs, true ) ) {
				return $type;
			}
		}

		return 'default';
	}

	/**
	 * Get questions for page type.
	 *
	 * @since 1.0.0
	 * @param string $page_type Page type.
	 * @return array Questions.
	 */
	private function get_page_type_questions( $page_type ) {
		if ( isset( $this->page_type_questions[ $page_type ] ) ) {
			return $this->page_type_questions[ $page_type ];
		}

		return $this->page_type_questions['default'];
	}

	/**
	 * Get content-specific questions.
	 *
	 * @since 1.0.0
	 * @param array $context Page context.
	 * @return array Questions.
	 */
	private function get_content_specific_questions( $context ) {
		$questions = array();

		// Product-specific questions
		if ( $context['product_id'] > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $context['product_id'] );
			if ( $product ) {
				$questions = $this->get_product_questions( $product );
			}
		}

		// Post-specific questions
		if ( $context['post_id'] > 0 ) {
			$post = get_post( $context['post_id'] );
			if ( $post ) {
				$questions = array_merge( $questions, $this->get_post_questions( $post, $context['page_type'] ) );
			}
		}

		// Title-based questions
		if ( ! empty( $context['page_title'] ) ) {
			$title_questions = $this->get_title_based_questions( $context['page_title'], $context['page_type'] );
			$questions = array_merge( $questions, $title_questions );
		}

		return $questions;
	}

	/**
	 * Get product-specific questions.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product WooCommerce product.
	 * @return array Questions.
	 */
	private function get_product_questions( $product ) {
		$questions = array();
		$name = $product->get_name();

		// Basic product question
		$questions[] = sprintf(
			/* translators: %s: Product name */
			__( 'Tell me more about %s', 'wp-ai-chatbot-leadgen-pro' ),
			$name
		);

		// Price-related if product has price
		if ( $product->get_price() ) {
			$questions[] = __( 'Are there any discounts available?', 'wp-ai-chatbot-leadgen-pro' );
		}

		// Variations if variable product
		if ( $product->is_type( 'variable' ) ) {
			$questions[] = __( 'What options are available?', 'wp-ai-chatbot-leadgen-pro' );
		}

		// Stock status
		if ( ! $product->is_in_stock() ) {
			$questions[] = __( 'When will this be back in stock?', 'wp-ai-chatbot-leadgen-pro' );
		}

		// Categories for comparison
		$categories = $product->get_category_ids();
		if ( ! empty( $categories ) ) {
			$questions[] = __( 'What are similar products?', 'wp-ai-chatbot-leadgen-pro' );
		}

		return $questions;
	}

	/**
	 * Get post-specific questions.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post      Post object.
	 * @param string  $page_type Page type.
	 * @return array Questions.
	 */
	private function get_post_questions( $post, $page_type ) {
		$questions = array();
		$title = $post->post_title;

		if ( $page_type === 'blog' ) {
			$questions[] = sprintf(
				/* translators: %s: Article title */
				__( 'Can you summarize "%s"?', 'wp-ai-chatbot-leadgen-pro' ),
				wp_trim_words( $title, 5 )
			);

			// Check for categories
			$categories = get_the_category( $post->ID );
			if ( ! empty( $categories ) ) {
				$questions[] = sprintf(
					/* translators: %s: Category name */
					__( 'What other articles do you have about %s?', 'wp-ai-chatbot-leadgen-pro' ),
					$categories[0]->name
				);
			}
		}

		return $questions;
	}

	/**
	 * Get questions based on page title.
	 *
	 * @since 1.0.0
	 * @param string $title     Page title.
	 * @param string $page_type Page type.
	 * @return array Questions.
	 */
	private function get_title_based_questions( $title, $page_type ) {
		$questions = array();

		// Trim long titles
		$short_title = wp_trim_words( $title, 5, '' );

		switch ( $page_type ) {
			case 'product':
				$questions[] = sprintf(
					/* translators: %s: Product name */
					__( 'What are the specs for %s?', 'wp-ai-chatbot-leadgen-pro' ),
					$short_title
				);
				break;

			case 'service':
				$questions[] = sprintf(
					/* translators: %s: Service name */
					__( 'How does %s work?', 'wp-ai-chatbot-leadgen-pro' ),
					$short_title
				);
				break;

			case 'blog':
				$questions[] = sprintf(
					/* translators: %s: Article title */
					__( 'Tell me more about %s', 'wp-ai-chatbot-leadgen-pro' ),
					$short_title
				);
				break;
		}

		return $questions;
	}

	/**
	 * Generate questions using AI.
	 *
	 * @since 1.0.0
	 * @param array $context Page context.
	 * @return array Generated questions.
	 */
	private function generate_ai_questions( $context ) {
		$provider_name = $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			return array();
		}

		// Prepare content summary (limit to avoid token issues)
		$content = wp_trim_words( $context['page_content'], 500 );

		$system_prompt = __( "Generate 3 helpful questions a visitor might ask about this page content. Questions should be:
1. Specific to the content
2. Clear and concise
3. Natural sounding
4. Actionable

Return only the questions, one per line, without numbering or bullet points.", 'wp-ai-chatbot-leadgen-pro' );

		$messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array(
				'role'    => 'user',
				'content' => sprintf(
					/* translators: 1: Page title, 2: Page content */
					__( "Page title: %1\$s\n\nContent:\n%2\$s", 'wp-ai-chatbot-leadgen-pro' ),
					$context['page_title'],
					$content
				),
			),
		);

		$response = $provider->chat_completion(
			$messages,
			array(
				'model'       => 'gpt-3.5-turbo',
				'temperature' => 0.7,
				'max_tokens'  => 200,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'AI question generation failed',
				array( 'error' => $response->get_error_message() )
			);
			return array();
		}

		// Parse response into questions
		$questions = array_filter(
			array_map( 'trim', explode( "\n", $response['content'] ) )
		);

		// Clean up questions (remove numbering, bullets, etc.)
		$questions = array_map( function( $q ) {
			return preg_replace( '/^[\d\.\-\*\)]+\s*/', '', $q );
		}, $questions );

		return array_slice( $questions, 0, 3 );
	}

	/**
	 * Register custom questions for a page type.
	 *
	 * @since 1.0.0
	 * @param string $page_type Page type.
	 * @param array  $questions Questions array.
	 */
	public function register_page_type_questions( $page_type, $questions ) {
		$this->page_type_questions[ $page_type ] = $questions;
	}

	/**
	 * Add custom questions to a page type.
	 *
	 * @since 1.0.0
	 * @param string $page_type Page type.
	 * @param array  $questions Questions to add.
	 */
	public function add_questions( $page_type, $questions ) {
		if ( ! isset( $this->page_type_questions[ $page_type ] ) ) {
			$this->page_type_questions[ $page_type ] = array();
		}

		$this->page_type_questions[ $page_type ] = array_merge(
			$this->page_type_questions[ $page_type ],
			$questions
		);
	}

	/**
	 * Get questions for AJAX request.
	 *
	 * @since 1.0.0
	 * @param array $request_data Request data.
	 * @return array Questions.
	 */
	public function get_questions_ajax( $request_data ) {
		$context = array(
			'page_url'     => sanitize_url( $request_data['page_url'] ?? '' ),
			'page_title'   => sanitize_text_field( $request_data['page_title'] ?? '' ),
			'page_type'    => sanitize_text_field( $request_data['page_type'] ?? '' ),
			'page_content' => sanitize_textarea_field( $request_data['page_content'] ?? '' ),
			'post_id'      => absint( $request_data['post_id'] ?? 0 ),
			'product_id'   => absint( $request_data['product_id'] ?? 0 ),
			'max_questions' => absint( $request_data['max_questions'] ?? 4 ),
			'use_ai'       => ! empty( $request_data['use_ai'] ),
		);

		return $this->get_questions( $context );
	}

	/**
	 * Get all registered page types.
	 *
	 * @since 1.0.0
	 * @return array Page types.
	 */
	public function get_page_types() {
		return array_keys( $this->page_type_questions );
	}

	/**
	 * Cache questions for a URL.
	 *
	 * @since 1.0.0
	 * @param string $url       Page URL.
	 * @param array  $questions Questions.
	 * @param int    $expiry    Cache expiry in seconds.
	 */
	public function cache_questions( $url, $questions, $expiry = 3600 ) {
		$cache_key = 'wp_ai_chatbot_questions_' . md5( $url );
		set_transient( $cache_key, $questions, $expiry );
	}

	/**
	 * Get cached questions for a URL.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array|false Questions or false if not cached.
	 */
	public function get_cached_questions( $url ) {
		$cache_key = 'wp_ai_chatbot_questions_' . md5( $url );
		return get_transient( $cache_key );
	}
}

