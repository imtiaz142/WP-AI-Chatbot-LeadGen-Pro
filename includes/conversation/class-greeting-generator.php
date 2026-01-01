<?php
/**
 * Greeting Generator.
 *
 * Generates personalized greetings for returning visitors with name and context.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Greeting_Generator {

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
	 * Conversation memory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory
	 */
	private $memory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->memory = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory();
	}

	/**
	 * Generate personalized greeting.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $context    Optional. Additional context.
	 * @return array Greeting data.
	 */
	public function generate( $session_id, $context = array() ) {
		// Get greeting data from memory
		$greeting_data = $this->memory->get_greeting_data( $session_id );

		// Get page context
		$page_context = $this->get_page_context( $context );

		// Get time context
		$time_context = $this->get_time_context();

		// Determine greeting type
		$greeting_type = $this->determine_greeting_type( $greeting_data, $page_context );

		// Generate greeting message
		$greeting = $this->build_greeting( $greeting_type, $greeting_data, $page_context, $time_context );

		// Generate suggested questions
		$questions = $this->generate_suggested_questions( $greeting_data, $page_context );

		return array(
			'greeting'            => $greeting,
			'type'                => $greeting_type,
			'is_returning'        => $greeting_data['is_returning'],
			'name'                => $greeting_data['name'],
			'suggested_questions' => $questions,
			'personalization'     => array(
				'name_used'       => ! empty( $greeting_data['name'] ),
				'context_used'    => ! empty( $greeting_data['last_topic'] ) || ! empty( $page_context['type'] ),
				'interests_used'  => ! empty( $greeting_data['interests'] ),
			),
		);
	}

	/**
	 * Determine greeting type based on context.
	 *
	 * @since 1.0.0
	 * @param array $greeting_data Greeting data from memory.
	 * @param array $page_context  Page context.
	 * @return string Greeting type.
	 */
	private function determine_greeting_type( $greeting_data, $page_context ) {
		// Returning visitor with name
		if ( $greeting_data['is_returning'] && ! empty( $greeting_data['name'] ) ) {
			return 'returning_named';
		}

		// Returning visitor without name
		if ( $greeting_data['is_returning'] ) {
			return 'returning_anonymous';
		}

		// First visit on specific page type
		if ( ! empty( $page_context['type'] ) ) {
			return 'first_visit_contextual';
		}

		// Generic first visit
		return 'first_visit';
	}

	/**
	 * Build greeting message.
	 *
	 * @since 1.0.0
	 * @param string $type          Greeting type.
	 * @param array  $greeting_data Greeting data.
	 * @param array  $page_context  Page context.
	 * @param array  $time_context  Time context.
	 * @return string Greeting message.
	 */
	private function build_greeting( $type, $greeting_data, $page_context, $time_context ) {
		$time_greeting = $time_context['greeting'];
		$name = $greeting_data['name'] ?? '';

		switch ( $type ) {
			case 'returning_named':
				return $this->build_returning_named_greeting( $name, $greeting_data, $page_context, $time_greeting );

			case 'returning_anonymous':
				return $this->build_returning_anonymous_greeting( $greeting_data, $page_context, $time_greeting );

			case 'first_visit_contextual':
				return $this->build_contextual_greeting( $page_context, $time_greeting );

			case 'first_visit':
			default:
				return $this->build_default_greeting( $time_greeting );
		}
	}

	/**
	 * Build greeting for returning visitor with name.
	 *
	 * @since 1.0.0
	 * @param string $name          User name.
	 * @param array  $greeting_data Greeting data.
	 * @param array  $page_context  Page context.
	 * @param string $time_greeting Time-based greeting.
	 * @return string Greeting message.
	 */
	private function build_returning_named_greeting( $name, $greeting_data, $page_context, $time_greeting ) {
		$greetings = array();

		// Time-based personalized greetings
		$greetings[] = sprintf(
			/* translators: 1: Time greeting (Good morning/afternoon/evening), 2: User name */
			__( '%1$s, %2$s! Great to see you again. How can I help you today?', 'wp-ai-chatbot-leadgen-pro' ),
			$time_greeting,
			$name
		);

		$greetings[] = sprintf(
			/* translators: 1: User name */
			__( 'Welcome back, %1$s! 👋 What can I assist you with today?', 'wp-ai-chatbot-leadgen-pro' ),
			$name
		);

		$greetings[] = sprintf(
			/* translators: 1: User name */
			__( 'Hi %1$s! Nice to see you again. How may I help?', 'wp-ai-chatbot-leadgen-pro' ),
			$name
		);

		// Add context-specific greeting if there's a last topic
		if ( ! empty( $greeting_data['last_topic'] ) ) {
			$greetings[] = sprintf(
				/* translators: 1: User name, 2: Last topic discussed */
				__( 'Welcome back, %1$s! Last time we talked about %2$s. Would you like to continue from there, or is there something else I can help with?', 'wp-ai-chatbot-leadgen-pro' ),
				$name,
				$greeting_data['last_topic']
			);
		}

		// Add interest-based greeting
		if ( ! empty( $greeting_data['interests'] ) ) {
			$primary_interest = $greeting_data['interests'][0];
			$interest_greetings = $this->get_interest_greeting( $name, $primary_interest );
			if ( $interest_greetings ) {
				$greetings[] = $interest_greetings;
			}
		}

		return $greetings[ array_rand( $greetings ) ];
	}

	/**
	 * Build greeting for returning anonymous visitor.
	 *
	 * @since 1.0.0
	 * @param array  $greeting_data Greeting data.
	 * @param array  $page_context  Page context.
	 * @param string $time_greeting Time-based greeting.
	 * @return string Greeting message.
	 */
	private function build_returning_anonymous_greeting( $greeting_data, $page_context, $time_greeting ) {
		$greetings = array();

		$greetings[] = sprintf(
			/* translators: 1: Time greeting */
			__( '%1$s! Welcome back. How can I help you today?', 'wp-ai-chatbot-leadgen-pro' ),
			$time_greeting
		);

		$greetings[] = __( 'Hey there! 👋 Good to see you again. What can I do for you?', 'wp-ai-chatbot-leadgen-pro' );

		$greetings[] = __( 'Welcome back! Is there anything I can help you with today?', 'wp-ai-chatbot-leadgen-pro' );

		// Add context-specific greeting if there's a last topic
		if ( ! empty( $greeting_data['last_topic'] ) ) {
			$greetings[] = sprintf(
				/* translators: 1: Last topic discussed */
				__( 'Welcome back! Last time we discussed %1$s. Would you like to pick up where we left off?', 'wp-ai-chatbot-leadgen-pro' ),
				$greeting_data['last_topic']
			);
		}

		// Add page-context greeting
		if ( ! empty( $page_context['type'] ) ) {
			$page_greeting = $this->get_page_type_greeting( $page_context, true );
			if ( $page_greeting ) {
				$greetings[] = $page_greeting;
			}
		}

		return $greetings[ array_rand( $greetings ) ];
	}

	/**
	 * Build contextual greeting for first-time visitor on specific page.
	 *
	 * @since 1.0.0
	 * @param array  $page_context  Page context.
	 * @param string $time_greeting Time-based greeting.
	 * @return string Greeting message.
	 */
	private function build_contextual_greeting( $page_context, $time_greeting ) {
		$page_greeting = $this->get_page_type_greeting( $page_context, false );

		if ( $page_greeting ) {
			return $page_greeting;
		}

		return $this->build_default_greeting( $time_greeting );
	}

	/**
	 * Build default greeting for first-time visitor.
	 *
	 * @since 1.0.0
	 * @param string $time_greeting Time-based greeting.
	 * @return string Greeting message.
	 */
	private function build_default_greeting( $time_greeting ) {
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );

		$greetings = array(
			sprintf(
				/* translators: 1: Time greeting, 2: Company name */
				__( '%1$s! 👋 Welcome to %2$s. I\'m here to help answer your questions. How can I assist you today?', 'wp-ai-chatbot-leadgen-pro' ),
				$time_greeting,
				$company_name
			),
			sprintf(
				/* translators: 1: Company name */
				__( 'Hi there! Welcome to %1$s. I\'m your AI assistant. Feel free to ask me anything!', 'wp-ai-chatbot-leadgen-pro' ),
				$company_name
			),
			__( 'Hello! 👋 I\'m here to help. What would you like to know?', 'wp-ai-chatbot-leadgen-pro' ),
			sprintf(
				/* translators: 1: Time greeting */
				__( '%1$s! How can I help you today?', 'wp-ai-chatbot-leadgen-pro' ),
				$time_greeting
			),
		);

		return $greetings[ array_rand( $greetings ) ];
	}

	/**
	 * Get greeting based on page type.
	 *
	 * @since 1.0.0
	 * @param array $page_context Page context.
	 * @param bool  $is_returning Whether visitor is returning.
	 * @return string|null Greeting or null.
	 */
	private function get_page_type_greeting( $page_context, $is_returning ) {
		$type = $page_context['type'] ?? '';
		$title = $page_context['title'] ?? '';
		$prefix = $is_returning ? __( 'Welcome back!', 'wp-ai-chatbot-leadgen-pro' ) . ' ' : '';

		switch ( $type ) {
			case 'product':
				if ( ! empty( $title ) ) {
					return $prefix . sprintf(
						/* translators: 1: Product name */
						__( 'I see you\'re looking at %1$s. Would you like to know more about it, or can I help you find something specific?', 'wp-ai-chatbot-leadgen-pro' ),
						$title
					);
				}
				return $prefix . __( 'Looking at our products? I can help you find exactly what you need or answer any questions!', 'wp-ai-chatbot-leadgen-pro' );

			case 'pricing':
				return $prefix . __( 'Interested in our pricing? I can help you find the best plan for your needs. What questions do you have?', 'wp-ai-chatbot-leadgen-pro' );

			case 'contact':
				return $prefix . __( 'Looking to get in touch? I can help you right now, or connect you with our team. What do you need?', 'wp-ai-chatbot-leadgen-pro' );

			case 'support':
			case 'help':
				return $prefix . __( 'Need help? I\'m here to assist! What issue can I help you resolve?', 'wp-ai-chatbot-leadgen-pro' );

			case 'blog':
			case 'article':
				if ( ! empty( $title ) ) {
					return $prefix . sprintf(
						/* translators: 1: Article title */
						__( 'Reading about "%1$s"? Feel free to ask if you have any questions about this topic!', 'wp-ai-chatbot-leadgen-pro' ),
						$title
					);
				}
				return $prefix . __( 'Exploring our blog? Let me know if you have questions about any of our content!', 'wp-ai-chatbot-leadgen-pro' );

			case 'service':
				if ( ! empty( $title ) ) {
					return $prefix . sprintf(
						/* translators: 1: Service name */
						__( 'Interested in our %1$s service? I\'d be happy to tell you more or answer any questions!', 'wp-ai-chatbot-leadgen-pro' ),
						$title
					);
				}
				return $prefix . __( 'Exploring our services? I can help you find the right solution for your needs!', 'wp-ai-chatbot-leadgen-pro' );

			case 'demo':
			case 'trial':
				return $prefix . __( 'Ready to try it out? I can help you get started or answer any questions before you begin!', 'wp-ai-chatbot-leadgen-pro' );

			case 'checkout':
			case 'cart':
				return $prefix . __( 'Ready to complete your purchase? Let me know if you have any questions or need assistance!', 'wp-ai-chatbot-leadgen-pro' );

			case 'faq':
				return $prefix . __( 'Looking for answers? I can help you find what you need faster. What\'s your question?', 'wp-ai-chatbot-leadgen-pro' );

			case 'about':
				$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );
				return $prefix . sprintf(
					/* translators: 1: Company name */
					__( 'Want to learn more about %1$s? I\'d be happy to tell you about us!', 'wp-ai-chatbot-leadgen-pro' ),
					$company_name
				);

			default:
				return null;
		}
	}

	/**
	 * Get interest-based greeting.
	 *
	 * @since 1.0.0
	 * @param string $name     User name.
	 * @param string $interest User interest.
	 * @return string|null Greeting or null.
	 */
	private function get_interest_greeting( $name, $interest ) {
		$interest_greetings = array(
			'pricing' => sprintf(
				/* translators: 1: User name */
				__( 'Hi %1$s! I noticed you were interested in our pricing. Would you like me to help you find the best plan?', 'wp-ai-chatbot-leadgen-pro' ),
				$name
			),
			'features' => sprintf(
				/* translators: 1: User name */
				__( 'Welcome back, %1$s! Last time you were curious about our features. Want to explore more?', 'wp-ai-chatbot-leadgen-pro' ),
				$name
			),
			'demo' => sprintf(
				/* translators: 1: User name */
				__( 'Hi %1$s! Ready to see a demo? I can help you get started or answer any questions first!', 'wp-ai-chatbot-leadgen-pro' ),
				$name
			),
			'integration' => sprintf(
				/* translators: 1: User name */
				__( 'Welcome back, %1$s! Still looking into integrations? I can help you find what works with your setup!', 'wp-ai-chatbot-leadgen-pro' ),
				$name
			),
			'support' => sprintf(
				/* translators: 1: User name */
				__( 'Hi %1$s! Need help with anything? I\'m here to assist!', 'wp-ai-chatbot-leadgen-pro' ),
				$name
			),
		);

		return $interest_greetings[ $interest ] ?? null;
	}

	/**
	 * Get page context from current page.
	 *
	 * @since 1.0.0
	 * @param array $context Provided context.
	 * @return array Page context.
	 */
	private function get_page_context( $context ) {
		$page_context = array(
			'type'  => '',
			'title' => '',
			'url'   => '',
		);

		// Use provided context
		if ( ! empty( $context['page_url'] ) ) {
			$page_context['url'] = $context['page_url'];
			$page_context['type'] = $this->detect_page_type( $context['page_url'] );
		}

		if ( ! empty( $context['page_title'] ) ) {
			$page_context['title'] = $context['page_title'];
		}

		if ( ! empty( $context['page_type'] ) ) {
			$page_context['type'] = $context['page_type'];
		}

		// Try to detect from WordPress context
		if ( empty( $page_context['type'] ) && function_exists( 'is_product' ) && is_product() ) {
			$page_context['type'] = 'product';
			$page_context['title'] = get_the_title();
		} elseif ( empty( $page_context['type'] ) && is_single() ) {
			$page_context['type'] = 'article';
			$page_context['title'] = get_the_title();
		} elseif ( empty( $page_context['type'] ) && is_page() ) {
			$page_context['title'] = get_the_title();
			$page_context['type'] = $this->detect_page_type_from_slug( get_post_field( 'post_name' ) );
		}

		return $page_context;
	}

	/**
	 * Detect page type from URL.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return string Page type.
	 */
	private function detect_page_type( $url ) {
		$url_lower = strtolower( $url );

		$type_patterns = array(
			'pricing'   => array( '/pricing', '/plans', '/packages', '/price' ),
			'contact'   => array( '/contact', '/get-in-touch', '/reach-us' ),
			'support'   => array( '/support', '/help', '/assistance' ),
			'faq'       => array( '/faq', '/frequently-asked', '/questions' ),
			'product'   => array( '/product/', '/shop/', '/store/' ),
			'checkout'  => array( '/checkout', '/cart', '/basket' ),
			'demo'      => array( '/demo', '/trial', '/try', '/start' ),
			'about'     => array( '/about', '/about-us', '/our-story' ),
			'blog'      => array( '/blog', '/news', '/articles' ),
			'service'   => array( '/service', '/solution', '/offering' ),
		);

		foreach ( $type_patterns as $type => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( strpos( $url_lower, $pattern ) !== false ) {
					return $type;
				}
			}
		}

		return '';
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

		$type_slugs = array(
			'pricing'  => array( 'pricing', 'plans', 'packages', 'price' ),
			'contact'  => array( 'contact', 'contact-us', 'get-in-touch' ),
			'support'  => array( 'support', 'help', 'help-center' ),
			'faq'      => array( 'faq', 'faqs', 'frequently-asked-questions' ),
			'demo'     => array( 'demo', 'request-demo', 'trial', 'free-trial' ),
			'about'    => array( 'about', 'about-us', 'our-story', 'our-team' ),
			'blog'     => array( 'blog', 'news', 'articles', 'resources' ),
			'service'  => array( 'services', 'solutions', 'offerings' ),
			'checkout' => array( 'checkout', 'cart', 'basket' ),
		);

		foreach ( $type_slugs as $type => $slugs ) {
			if ( in_array( $slug_lower, $slugs, true ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Get time-based context.
	 *
	 * @since 1.0.0
	 * @return array Time context.
	 */
	private function get_time_context() {
		$hour = intval( current_time( 'G' ) );

		if ( $hour >= 5 && $hour < 12 ) {
			return array(
				'period'   => 'morning',
				'greeting' => __( 'Good morning', 'wp-ai-chatbot-leadgen-pro' ),
			);
		} elseif ( $hour >= 12 && $hour < 17 ) {
			return array(
				'period'   => 'afternoon',
				'greeting' => __( 'Good afternoon', 'wp-ai-chatbot-leadgen-pro' ),
			);
		} elseif ( $hour >= 17 && $hour < 21 ) {
			return array(
				'period'   => 'evening',
				'greeting' => __( 'Good evening', 'wp-ai-chatbot-leadgen-pro' ),
			);
		} else {
			return array(
				'period'   => 'night',
				'greeting' => __( 'Hello', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}
	}

	/**
	 * Generate suggested questions based on context.
	 *
	 * @since 1.0.0
	 * @param array $greeting_data Greeting data.
	 * @param array $page_context  Page context.
	 * @return array Suggested questions.
	 */
	private function generate_suggested_questions( $greeting_data, $page_context ) {
		$questions = array();

		// Page-specific questions
		$page_type = $page_context['type'] ?? '';
		$page_questions = $this->get_page_type_questions( $page_type, $page_context );
		$questions = array_merge( $questions, $page_questions );

		// Interest-based questions
		if ( ! empty( $greeting_data['interests'] ) ) {
			foreach ( $greeting_data['interests'] as $interest ) {
				$interest_questions = $this->get_interest_questions( $interest );
				$questions = array_merge( $questions, $interest_questions );
			}
		}

		// Add generic questions if not enough
		if ( count( $questions ) < 3 ) {
			$generic = $this->get_generic_questions();
			$questions = array_merge( $questions, $generic );
		}

		// Remove duplicates and limit
		$questions = array_unique( $questions );
		return array_slice( $questions, 0, 4 );
	}

	/**
	 * Get questions for page type.
	 *
	 * @since 1.0.0
	 * @param string $page_type    Page type.
	 * @param array  $page_context Page context.
	 * @return array Questions.
	 */
	private function get_page_type_questions( $page_type, $page_context ) {
		$title = $page_context['title'] ?? '';

		$page_questions = array(
			'pricing' => array(
				__( 'What plans do you offer?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Is there a free trial?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s included in each plan?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'product' => array(
				$title ? sprintf( __( 'Tell me more about %s', 'wp-ai-chatbot-leadgen-pro' ), $title ) : __( 'Tell me about this product', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What are the key features?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Is this right for my needs?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'contact' => array(
				__( 'What\'s the fastest way to reach you?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I schedule a call?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'support' => array(
				__( 'I need help with an issue', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Where can I find documentation?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'demo' => array(
				__( 'How long is the trial?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What\'s included in the demo?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Can I get a personalized walkthrough?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'checkout' => array(
				__( 'Do you have a discount code?', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'What payment methods do you accept?', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'faq' => array(
				__( 'I have a question not listed here', 'wp-ai-chatbot-leadgen-pro' ),
			),
		);

		return $page_questions[ $page_type ] ?? array();
	}

	/**
	 * Get questions based on interest.
	 *
	 * @since 1.0.0
	 * @param string $interest User interest.
	 * @return array Questions.
	 */
	private function get_interest_questions( $interest ) {
		$interest_questions = array(
			'pricing'       => array( __( 'Show me pricing options', 'wp-ai-chatbot-leadgen-pro' ) ),
			'features'      => array( __( 'What features do you offer?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'integration'   => array( __( 'What integrations are available?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'demo'          => array( __( 'Can I see a demo?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'support'       => array( __( 'How do I get support?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'enterprise'    => array( __( 'Do you have enterprise plans?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'security'      => array( __( 'What security measures do you have?', 'wp-ai-chatbot-leadgen-pro' ) ),
			'customization' => array( __( 'Can it be customized?', 'wp-ai-chatbot-leadgen-pro' ) ),
		);

		return $interest_questions[ $interest ] ?? array();
	}

	/**
	 * Get generic suggested questions.
	 *
	 * @since 1.0.0
	 * @return array Questions.
	 */
	private function get_generic_questions() {
		return array(
			__( 'What do you offer?', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'How can you help me?', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Tell me about your services', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'I\'d like to speak to someone', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Get custom greeting if configured.
	 *
	 * @since 1.0.0
	 * @return string|null Custom greeting or null.
	 */
	public function get_custom_greeting() {
		return $this->config->get( 'custom_greeting_message', null );
	}

	/**
	 * Store greeting interaction.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $greeting   Greeting data.
	 */
	public function record_greeting( $session_id, $greeting ) {
		$this->memory->store_interaction( $session_id, 'last_greeting', array(
			'greeting' => $greeting['greeting'],
			'type'     => $greeting['type'],
			'timestamp' => current_time( 'mysql' ),
		) );
	}
}

