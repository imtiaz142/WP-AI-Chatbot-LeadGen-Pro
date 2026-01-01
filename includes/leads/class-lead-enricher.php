<?php
/**
 * Lead Enricher.
 *
 * Fetches additional data from third-party enrichment providers
 * like Clearbit, Hunter.io, and FullContact to enhance lead profiles.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher {

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
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $lead_storage;

	/**
	 * Provider configurations.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const PROVIDERS = array(
		'clearbit' => array(
			'name'        => 'Clearbit',
			'base_url'    => 'https://person.clearbit.com/v2/combined/find',
			'company_url' => 'https://company.clearbit.com/v2/companies/find',
			'auth_type'   => 'bearer',
			'rate_limit'  => 600, // requests per minute
		),
		'hunter' => array(
			'name'        => 'Hunter.io',
			'base_url'    => 'https://api.hunter.io/v2/email-verifier',
			'finder_url'  => 'https://api.hunter.io/v2/domain-search',
			'auth_type'   => 'query',
			'auth_param'  => 'api_key',
			'rate_limit'  => 100,
		),
		'fullcontact' => array(
			'name'        => 'FullContact',
			'base_url'    => 'https://api.fullcontact.com/v3/person.enrich',
			'company_url' => 'https://api.fullcontact.com/v3/company.enrich',
			'auth_type'   => 'header',
			'auth_header' => 'Authorization',
			'rate_limit'  => 300,
		),
		'apollo' => array(
			'name'        => 'Apollo.io',
			'base_url'    => 'https://api.apollo.io/v1/people/match',
			'auth_type'   => 'header',
			'auth_header' => 'X-Api-Key',
			'rate_limit'  => 100,
		),
	);

	/**
	 * Cache prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_ai_chatbot_enrichment_';

	/**
	 * Cache duration in seconds (7 days).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const CACHE_DURATION = 604800;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->lead_storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Auto-enrich on lead creation
		add_action( 'wp_ai_chatbot_lead_created', array( $this, 'maybe_enrich_lead' ), 20, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_enrich_lead', array( $this, 'ajax_enrich_lead' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_get_enrichment', array( $this, 'ajax_get_enrichment' ) );

		// Background enrichment
		add_action( 'wp_ai_chatbot_enrich_lead_async', array( $this, 'enrich_lead_async' ) );
	}

	/**
	 * Enrich a lead with data from all configured providers.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $options Enrichment options.
	 * @return array Enrichment results.
	 */
	public function enrich( $lead_id, $options = array() ) {
		$defaults = array(
			'providers'   => array(), // Empty = all configured
			'force'       => false,   // Force refresh even if cached
			'async'       => false,   // Run asynchronously
		);
		$options = wp_parse_args( $options, $defaults );

		$lead = $this->lead_storage ? $this->lead_storage->get( $lead_id ) : null;

		if ( ! $lead || empty( $lead['email'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Lead not found or no email address', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		$email = $lead['email'];

		// Check cache first
		if ( ! $options['force'] ) {
			$cached = $this->get_cached_enrichment( $email );
			if ( $cached ) {
				return array(
					'success' => true,
					'cached'  => true,
					'data'    => $cached,
				);
			}
		}

		// Schedule async if requested
		if ( $options['async'] ) {
			wp_schedule_single_event( time(), 'wp_ai_chatbot_enrich_lead_async', array( $lead_id, $options ) );
			return array(
				'success'   => true,
				'scheduled' => true,
			);
		}

		// Get configured providers
		$providers = $this->get_configured_providers();

		if ( ! empty( $options['providers'] ) ) {
			$providers = array_intersect_key( $providers, array_flip( $options['providers'] ) );
		}

		if ( empty( $providers ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No enrichment providers configured', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Fetch from each provider
		$results = array();
		$combined = array();

		foreach ( $providers as $provider_id => $api_key ) {
			$result = $this->fetch_from_provider( $provider_id, $email, $lead, $api_key );
			$results[ $provider_id ] = $result;

			if ( $result['success'] && ! empty( $result['data'] ) ) {
				$combined = $this->merge_enrichment_data( $combined, $result['data'], $provider_id );
			}
		}

		// Store combined enrichment
		if ( ! empty( $combined ) ) {
			$this->store_enrichment( $lead_id, $combined, $results );
			$this->cache_enrichment( $email, $combined );
		}

		return array(
			'success'  => ! empty( $combined ),
			'data'     => $combined,
			'results'  => $results,
			'cached'   => false,
		);
	}

	/**
	 * Fetch enrichment data from a specific provider.
	 *
	 * @since 1.0.0
	 * @param string $provider_id Provider ID.
	 * @param string $email       Email address.
	 * @param array  $lead        Lead data.
	 * @param string $api_key     API key.
	 * @return array Result.
	 */
	private function fetch_from_provider( $provider_id, $email, $lead, $api_key ) {
		$provider = self::PROVIDERS[ $provider_id ] ?? null;

		if ( ! $provider ) {
			return array( 'success' => false, 'error' => 'Unknown provider' );
		}

		// Check rate limiting
		if ( $this->is_rate_limited( $provider_id ) ) {
			return array( 'success' => false, 'error' => 'Rate limited' );
		}

		switch ( $provider_id ) {
			case 'clearbit':
				return $this->fetch_clearbit( $email, $lead, $api_key );

			case 'hunter':
				return $this->fetch_hunter( $email, $lead, $api_key );

			case 'fullcontact':
				return $this->fetch_fullcontact( $email, $lead, $api_key );

			case 'apollo':
				return $this->fetch_apollo( $email, $lead, $api_key );

			default:
				return array( 'success' => false, 'error' => 'Provider not implemented' );
		}
	}

	/**
	 * Fetch from Clearbit.
	 *
	 * @since 1.0.0
	 * @param string $email   Email address.
	 * @param array  $lead    Lead data.
	 * @param string $api_key API key.
	 * @return array Result.
	 */
	private function fetch_clearbit( $email, $lead, $api_key ) {
		$url = add_query_arg( 'email', urlencode( $email ), self::PROVIDERS['clearbit']['base_url'] );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
		) );

		$this->record_request( 'clearbit' );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Clearbit API error', array(
				'error' => $response->get_error_message(),
			) );
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 404 ) {
			return array( 'success' => true, 'data' => array(), 'not_found' => true );
		}

		if ( $code !== 200 || ! $data ) {
			return array( 'success' => false, 'error' => "HTTP $code" );
		}

		// Parse Clearbit response
		$enriched = array();

		// Person data
		if ( ! empty( $data['person'] ) ) {
			$person = $data['person'];
			$enriched['first_name'] = $person['name']['givenName'] ?? null;
			$enriched['last_name'] = $person['name']['familyName'] ?? null;
			$enriched['full_name'] = $person['name']['fullName'] ?? null;
			$enriched['title'] = $person['employment']['title'] ?? null;
			$enriched['role'] = $person['employment']['role'] ?? null;
			$enriched['seniority'] = $person['employment']['seniority'] ?? null;
			$enriched['linkedin'] = $person['linkedin']['handle'] ?? null;
			$enriched['twitter'] = $person['twitter']['handle'] ?? null;
			$enriched['avatar'] = $person['avatar'] ?? null;
			$enriched['location'] = $person['location'] ?? null;
			$enriched['timezone'] = $person['timeZone'] ?? null;
			$enriched['bio'] = $person['bio'] ?? null;
		}

		// Company data
		if ( ! empty( $data['company'] ) ) {
			$company = $data['company'];
			$enriched['company_name'] = $company['name'] ?? null;
			$enriched['company_domain'] = $company['domain'] ?? null;
			$enriched['company_logo'] = $company['logo'] ?? null;
			$enriched['company_description'] = $company['description'] ?? null;
			$enriched['company_industry'] = $company['category']['industry'] ?? null;
			$enriched['company_sector'] = $company['category']['sector'] ?? null;
			$enriched['company_type'] = $company['type'] ?? null;
			$enriched['company_size'] = $company['metrics']['employees'] ?? null;
			$enriched['company_size_range'] = $company['metrics']['employeesRange'] ?? null;
			$enriched['company_revenue'] = $company['metrics']['estimatedAnnualRevenue'] ?? null;
			$enriched['company_founded'] = $company['foundedYear'] ?? null;
			$enriched['company_location'] = $company['location'] ?? null;
			$enriched['company_linkedin'] = $company['linkedin']['handle'] ?? null;
			$enriched['company_twitter'] = $company['twitter']['handle'] ?? null;
			$enriched['company_technologies'] = $company['tech'] ?? array();
			$enriched['company_tags'] = $company['tags'] ?? array();
		}

		return array(
			'success'  => true,
			'data'     => array_filter( $enriched ),
			'provider' => 'clearbit',
		);
	}

	/**
	 * Fetch from Hunter.io.
	 *
	 * @since 1.0.0
	 * @param string $email   Email address.
	 * @param array  $lead    Lead data.
	 * @param string $api_key API key.
	 * @return array Result.
	 */
	private function fetch_hunter( $email, $lead, $api_key ) {
		// Verify email
		$url = add_query_arg( array(
			'email'   => urlencode( $email ),
			'api_key' => $api_key,
		), self::PROVIDERS['hunter']['base_url'] );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
		) );

		$this->record_request( 'hunter' );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 || ! isset( $data['data'] ) ) {
			return array( 'success' => false, 'error' => "HTTP $code" );
		}

		$hunter_data = $data['data'];
		$enriched = array();

		// Email verification data
		$enriched['email_status'] = $hunter_data['status'] ?? null;
		$enriched['email_score'] = $hunter_data['score'] ?? null;
		$enriched['email_deliverable'] = $hunter_data['result'] === 'deliverable';
		$enriched['email_disposable'] = $hunter_data['disposable'] ?? false;
		$enriched['email_webmail'] = $hunter_data['webmail'] ?? false;
		$enriched['email_mx_records'] = $hunter_data['mx_records'] ?? false;
		$enriched['email_smtp_server'] = $hunter_data['smtp_server'] ?? null;
		$enriched['email_smtp_check'] = $hunter_data['smtp_check'] ?? false;
		$enriched['email_catch_all'] = $hunter_data['accept_all'] ?? false;

		// Get first/last name from email pattern
		if ( ! empty( $hunter_data['first_name'] ) ) {
			$enriched['first_name'] = $hunter_data['first_name'];
		}
		if ( ! empty( $hunter_data['last_name'] ) ) {
			$enriched['last_name'] = $hunter_data['last_name'];
		}

		// If we have domain, do domain search for company info
		$domain = $this->extract_domain( $email );
		if ( $domain && ! in_array( $domain, array( 'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com' ), true ) ) {
			$company_data = $this->fetch_hunter_domain( $domain, $api_key );
			if ( ! empty( $company_data ) ) {
				$enriched = array_merge( $enriched, $company_data );
			}
		}

		return array(
			'success'  => true,
			'data'     => array_filter( $enriched ),
			'provider' => 'hunter',
		);
	}

	/**
	 * Fetch company data from Hunter domain search.
	 *
	 * @since 1.0.0
	 * @param string $domain  Domain name.
	 * @param string $api_key API key.
	 * @return array Company data.
	 */
	private function fetch_hunter_domain( $domain, $api_key ) {
		$url = add_query_arg( array(
			'domain'  => urlencode( $domain ),
			'api_key' => $api_key,
		), self::PROVIDERS['hunter']['finder_url'] );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
		) );

		$this->record_request( 'hunter' );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 || ! isset( $data['data'] ) ) {
			return array();
		}

		$company = $data['data'];

		return array(
			'company_name'        => $company['organization'] ?? null,
			'company_domain'      => $company['domain'] ?? null,
			'company_industry'    => $company['industry'] ?? null,
			'company_type'        => $company['company_type'] ?? null,
			'company_linkedin'    => $company['linkedin'] ?? null,
			'company_twitter'     => $company['twitter'] ?? null,
			'company_facebook'    => $company['facebook'] ?? null,
			'company_instagram'   => $company['instagram'] ?? null,
			'company_youtube'     => $company['youtube'] ?? null,
		);
	}

	/**
	 * Fetch from FullContact.
	 *
	 * @since 1.0.0
	 * @param string $email   Email address.
	 * @param array  $lead    Lead data.
	 * @param string $api_key API key.
	 * @return array Result.
	 */
	private function fetch_fullcontact( $email, $lead, $api_key ) {
		$response = wp_remote_post( self::PROVIDERS['fullcontact']['base_url'], array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'email' => $email,
			) ),
		) );

		$this->record_request( 'fullcontact' );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 404 ) {
			return array( 'success' => true, 'data' => array(), 'not_found' => true );
		}

		if ( $code !== 200 || ! $data ) {
			return array( 'success' => false, 'error' => "HTTP $code" );
		}

		$enriched = array();

		// Person data
		$enriched['full_name'] = $data['fullName'] ?? null;
		$enriched['age_range'] = $data['ageRange'] ?? null;
		$enriched['gender'] = $data['gender'] ?? null;
		$enriched['location'] = $data['location'] ?? null;
		$enriched['title'] = $data['title'] ?? null;
		$enriched['organization'] = $data['organization'] ?? null;
		$enriched['bio'] = $data['bio'] ?? null;
		$enriched['avatar'] = $data['avatar'] ?? null;

		// Social profiles
		if ( ! empty( $data['linkedin'] ) ) {
			$enriched['linkedin'] = $data['linkedin'];
		}
		if ( ! empty( $data['twitter'] ) ) {
			$enriched['twitter'] = $data['twitter'];
		}
		if ( ! empty( $data['facebook'] ) ) {
			$enriched['facebook'] = $data['facebook'];
		}

		// Details
		if ( ! empty( $data['details'] ) ) {
			$details = $data['details'];
			
			if ( ! empty( $details['name'] ) ) {
				$enriched['first_name'] = $details['name']['given'] ?? null;
				$enriched['last_name'] = $details['name']['family'] ?? null;
			}

			if ( ! empty( $details['employment'] ) && is_array( $details['employment'] ) ) {
				$current = $details['employment'][0] ?? array();
				$enriched['title'] = $current['title'] ?? $enriched['title'];
				$enriched['company_name'] = $current['name'] ?? null;
				$enriched['company_domain'] = $current['domain'] ?? null;
			}

			if ( ! empty( $details['photos'] ) && is_array( $details['photos'] ) ) {
				$enriched['avatar'] = $details['photos'][0]['url'] ?? $enriched['avatar'];
			}
		}

		return array(
			'success'  => true,
			'data'     => array_filter( $enriched ),
			'provider' => 'fullcontact',
		);
	}

	/**
	 * Fetch from Apollo.io.
	 *
	 * @since 1.0.0
	 * @param string $email   Email address.
	 * @param array  $lead    Lead data.
	 * @param string $api_key API key.
	 * @return array Result.
	 */
	private function fetch_apollo( $email, $lead, $api_key ) {
		$response = wp_remote_post( self::PROVIDERS['apollo']['base_url'], array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Api-Key'    => $api_key,
			),
			'body'    => wp_json_encode( array(
				'email' => $email,
			) ),
		) );

		$this->record_request( 'apollo' );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 || ! isset( $data['person'] ) ) {
			return array( 'success' => false, 'error' => "HTTP $code" );
		}

		$person = $data['person'];
		$enriched = array();

		// Person data
		$enriched['first_name'] = $person['first_name'] ?? null;
		$enriched['last_name'] = $person['last_name'] ?? null;
		$enriched['full_name'] = $person['name'] ?? null;
		$enriched['title'] = $person['title'] ?? null;
		$enriched['headline'] = $person['headline'] ?? null;
		$enriched['linkedin'] = $person['linkedin_url'] ?? null;
		$enriched['twitter'] = $person['twitter_url'] ?? null;
		$enriched['avatar'] = $person['photo_url'] ?? null;
		$enriched['city'] = $person['city'] ?? null;
		$enriched['state'] = $person['state'] ?? null;
		$enriched['country'] = $person['country'] ?? null;
		$enriched['seniority'] = $person['seniority'] ?? null;
		$enriched['departments'] = $person['departments'] ?? array();

		// Organization data
		if ( ! empty( $person['organization'] ) ) {
			$org = $person['organization'];
			$enriched['company_name'] = $org['name'] ?? null;
			$enriched['company_domain'] = $org['primary_domain'] ?? null;
			$enriched['company_logo'] = $org['logo_url'] ?? null;
			$enriched['company_industry'] = $org['industry'] ?? null;
			$enriched['company_size'] = $org['estimated_num_employees'] ?? null;
			$enriched['company_founded'] = $org['founded_year'] ?? null;
			$enriched['company_linkedin'] = $org['linkedin_url'] ?? null;
			$enriched['company_twitter'] = $org['twitter_url'] ?? null;
			$enriched['company_facebook'] = $org['facebook_url'] ?? null;
			$enriched['company_phone'] = $org['phone'] ?? null;
		}

		return array(
			'success'  => true,
			'data'     => array_filter( $enriched ),
			'provider' => 'apollo',
		);
	}

	/**
	 * Merge enrichment data from multiple providers.
	 *
	 * @since 1.0.0
	 * @param array  $existing    Existing data.
	 * @param array  $new_data    New data.
	 * @param string $provider_id Provider ID.
	 * @return array Merged data.
	 */
	private function merge_enrichment_data( $existing, $new_data, $provider_id ) {
		// Priority order for providers (first = highest priority)
		$priority = array( 'clearbit', 'apollo', 'fullcontact', 'hunter' );
		$provider_priority = array_search( $provider_id, $priority, true );

		foreach ( $new_data as $key => $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			// If key doesn't exist, add it
			if ( ! isset( $existing[ $key ] ) || empty( $existing[ $key ] ) ) {
				$existing[ $key ] = $value;
				$existing[ $key . '_source' ] = $provider_id;
			}
			// If key exists, only overwrite if new provider has higher priority
			elseif ( isset( $existing[ $key . '_source' ] ) ) {
				$existing_priority = array_search( $existing[ $key . '_source' ], $priority, true );
				if ( $provider_priority !== false && $provider_priority < $existing_priority ) {
					$existing[ $key ] = $value;
					$existing[ $key . '_source' ] = $provider_id;
				}
			}
		}

		return $existing;
	}

	/**
	 * Store enrichment data.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id  Lead ID.
	 * @param array $data     Enrichment data.
	 * @param array $results  Provider results.
	 */
	private function store_enrichment( $lead_id, $data, $results ) {
		if ( ! $this->lead_storage ) {
			return;
		}

		// Get current custom fields
		$lead = $this->lead_storage->get( $lead_id );
		$custom_fields = $lead['custom_fields'] ?? array();

		// Store enrichment data
		$custom_fields['enrichment'] = $data;
		$custom_fields['enrichment_date'] = current_time( 'mysql' );
		$custom_fields['enrichment_providers'] = array_keys( array_filter( $results, function( $r ) {
			return ! empty( $r['success'] );
		} ) );

		$this->lead_storage->update( $lead_id, array(
			'custom_fields' => $custom_fields,
		) );

		// Update main lead fields if they're empty
		$updates = array();

		if ( empty( $lead['name'] ) && ! empty( $data['full_name'] ) ) {
			$updates['name'] = $data['full_name'];
		}

		if ( empty( $lead['company'] ) && ! empty( $data['company_name'] ) ) {
			$updates['company'] = $data['company_name'];
		}

		// Store geodata if available
		if ( ! empty( $data['location'] ) || ! empty( $data['city'] ) ) {
			$geo = array(
				'location' => $data['location'] ?? null,
				'city'     => $data['city'] ?? null,
				'state'    => $data['state'] ?? null,
				'country'  => $data['country'] ?? null,
				'timezone' => $data['timezone'] ?? null,
			);
			$updates['geo_data'] = array_filter( $geo );
		}

		if ( ! empty( $updates ) ) {
			$this->lead_storage->update( $lead_id, $updates );
		}

		$this->logger->info( 'Lead enriched', array(
			'lead_id'   => $lead_id,
			'providers' => $custom_fields['enrichment_providers'],
		) );

		// Trigger rescore after enrichment
		do_action( 'wp_ai_chatbot_lead_enriched', $lead_id, $data );
	}

	/**
	 * Cache enrichment data.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @param array  $data  Enrichment data.
	 */
	private function cache_enrichment( $email, $data ) {
		$cache_key = self::CACHE_PREFIX . md5( strtolower( $email ) );
		set_transient( $cache_key, $data, self::CACHE_DURATION );
	}

	/**
	 * Get cached enrichment data.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return array|null Cached data or null.
	 */
	private function get_cached_enrichment( $email ) {
		$cache_key = self::CACHE_PREFIX . md5( strtolower( $email ) );
		return get_transient( $cache_key ) ?: null;
	}

	/**
	 * Get configured providers with API keys.
	 *
	 * @since 1.0.0
	 * @return array Provider IDs with API keys.
	 */
	private function get_configured_providers() {
		$providers = array();
		$api_keys = $this->config->get( 'enrichment_api_keys', array() );

		foreach ( self::PROVIDERS as $provider_id => $config ) {
			if ( ! empty( $api_keys[ $provider_id ] ) ) {
				$providers[ $provider_id ] = $api_keys[ $provider_id ];
			}
		}

		return $providers;
	}

	/**
	 * Check if provider is rate limited.
	 *
	 * @since 1.0.0
	 * @param string $provider_id Provider ID.
	 * @return bool True if rate limited.
	 */
	private function is_rate_limited( $provider_id ) {
		$key = 'wp_ai_chatbot_enrichment_rate_' . $provider_id;
		$requests = get_transient( $key ) ?: 0;
		$limit = self::PROVIDERS[ $provider_id ]['rate_limit'] ?? 100;

		return $requests >= $limit;
	}

	/**
	 * Record API request for rate limiting.
	 *
	 * @since 1.0.0
	 * @param string $provider_id Provider ID.
	 */
	private function record_request( $provider_id ) {
		$key = 'wp_ai_chatbot_enrichment_rate_' . $provider_id;
		$requests = get_transient( $key ) ?: 0;
		set_transient( $key, $requests + 1, 60 ); // Reset every minute
	}

	/**
	 * Extract domain from email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return string|null Domain or null.
	 */
	private function extract_domain( $email ) {
		$parts = explode( '@', $email );
		return $parts[1] ?? null;
	}

	/**
	 * Maybe enrich lead on creation.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function maybe_enrich_lead( $lead_id, $lead_data ) {
		// Check if auto-enrichment is enabled
		if ( ! $this->config->get( 'auto_enrich_leads', false ) ) {
			return;
		}

		// Schedule async enrichment
		$this->enrich( $lead_id, array( 'async' => true ) );
	}

	/**
	 * Async enrichment handler.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $options Options.
	 */
	public function enrich_lead_async( $lead_id, $options = array() ) {
		$options['async'] = false; // Prevent infinite loop
		$this->enrich( $lead_id, $options );
	}

	/**
	 * AJAX handler for enriching a lead.
	 *
	 * @since 1.0.0
	 */
	public function ajax_enrich_lead() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );
		$force = ! empty( $_POST['force'] );
		$providers = isset( $_POST['providers'] ) ? array_map( 'sanitize_text_field', (array) $_POST['providers'] ) : array();

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$result = $this->enrich( $lead_id, array(
			'force'     => $force,
			'providers' => $providers,
		) );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result, 500 );
		}
	}

	/**
	 * AJAX handler for getting enrichment data.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_enrichment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( ! $lead_id || ! $this->lead_storage ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$lead = $this->lead_storage->get( $lead_id );

		if ( ! $lead ) {
			wp_send_json_error( array( 'message' => 'Lead not found' ), 404 );
		}

		$custom_fields = $lead['custom_fields'] ?? array();

		wp_send_json_success( array(
			'enrichment' => $custom_fields['enrichment'] ?? null,
			'date'       => $custom_fields['enrichment_date'] ?? null,
			'providers'  => $custom_fields['enrichment_providers'] ?? array(),
		) );
	}

	/**
	 * Get available providers.
	 *
	 * @since 1.0.0
	 * @return array Provider info.
	 */
	public function get_available_providers() {
		$configured = $this->get_configured_providers();

		return array_map( function( $id, $config ) use ( $configured ) {
			return array(
				'id'         => $id,
				'name'       => $config['name'],
				'configured' => isset( $configured[ $id ] ),
			);
		}, array_keys( self::PROVIDERS ), self::PROVIDERS );
	}
}

