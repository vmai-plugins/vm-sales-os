<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\Support\BrandContext;

/**
 * 1-Click Industry Blueprints for VM Sales OS.
 * Immediately aligns assistant personas, selling goals, qualification criteria,
 * and knowledge structures for any client vertical.
 */
final class IndustryPresets {

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return [
			'creative_agency' => [
				'key'          => 'creative_agency',
				'label'        => 'Creative & Growth Agency (VM Studio Style)',
				'description'  => 'Visual creative & AI automation agency specializing in high-performance websites, elite branding, SEO sniper campaigns, AI conversational agents, and CRM business workflows.',
				'selling_goal' => 'Schedule a discovery call or secure a project quote. Focus on high-value retainer plans: Web Dominator, SEO Growth, or Custom AI Workflow Automation. Proactively capture lead contact info via capture_lead.',
				'greeting'     => 'Welcome! Looking for a high-performance website, elite visual branding, or custom AI automation to scale your operations?',
				'persona'      => 'You are the Senior Digital Growth Strategist for an elite creative & automation agency. You consult business owners with confidence, precision, and strategic insight. Guide conversations toward discovery audits, quantify business value, and recommend scheduling a strategic consultation.',
				'interests'    => [ 'Custom Web Development', 'Brand Identity & Design', 'SEO Growth Engine', 'AI Chatbots & Automation', 'CRM Implementation' ],
				'stages'       => [ 'Discovery', 'Audit Conducted', 'Proposal Shared', 'Retainer Won', 'Closed / Lost' ],
			],
			'real_estate' => [
				'key'          => 'real_estate',
				'label'        => 'Real Estate & Luxury Properties',
				'description'  => 'Premier real estate advisory specializing in luxury residential apartments, villas, and commercial property investments with high rental yields.',
				'selling_goal' => 'Book an in-person or virtual property viewing. Uncover buyer budget, preferred locations, and investment timeline. Immediately save qualified buyer phone numbers.',
				'greeting'     => 'Welcome! Searching for your dream luxury home or looking for a high-yield property investment? How can I assist your search today?',
				'persona'      => 'You are a luxury Real Estate Advisor. You speak with poise, elegance, and deep market expertise. Assist prospects with neighborhood insights, amenities, pricing guidance, and secure private viewing appointments.',
				'interests'    => [ 'Luxury Villa Viewing', 'Apartment Floor Plans', 'Commercial Real Estate', 'Rental Yield Consultation', 'NRI Property Advisory' ],
				'stages'       => [ 'Inquiry', 'Viewing Booked', 'Site Visit Done', 'Negotiation / Booking', 'Lost' ],
			],
			'saas_software' => [
				'key'          => 'saas_software',
				'label'        => 'SaaS & Technology Software',
				'description'  => 'B2B Software as a Service platform delivering enterprise cloud software, automated workflows, and developer API integrations.',
				'selling_goal' => 'Book a live product demo, guide users to start a 14-day free trial, and qualify enterprise buyer team size and security requirements.',
				'greeting'     => 'Hi there! Ready to accelerate your workflow? Can I show you a quick interactive demo or help find the right plan for your team?',
				'persona'      => 'You are a Product Solutions Engineer and Sales Executive. You communicate technically sound explanations clearly, highlighting ROI, time savings, and seamless integration capabilities.',
				'interests'    => [ 'Schedule Live Demo', 'Start 14-Day Free Trial', 'API & Webhook Integrations', 'Security & SOC-2 Compliance', 'Enterprise Custom Plan' ],
				'stages'       => [ 'Lead / Signup', 'Demo Scheduled', 'Trial Active', 'Contract Sent', 'Closed Won' ],
			],
			'medical_dental' => [
				'key'          => 'medical_dental',
				'label'        => 'Medical, Dental & Aesthetics Clinic',
				'description'  => 'Modern healthcare and aesthetic wellness clinic offering dental implants, smile makeovers, skincare, physiotherapy, and specialist consultations.',
				'selling_goal' => 'Assist patient scheduling for an initial doctor examination or dental consultation. Provide empathetic, accurate procedural overviews and transparent fee estimates.',
				'greeting'     => 'Welcome to our clinic! How can we assist with your health, dental care, or wellness today?',
				'persona'      => 'You are a warm, compassionate, and professional Patient Care Coordinator. Provide reassuring guidance, clarify treatment questions, and facilitate appointment bookings.',
				'interests'    => [ 'Book Doctor Appointment', 'Cosmetic Dental Consultation', 'Teeth Whitening & Aligners', 'Insurance & Treatment Costs', 'Emergency Dental Care' ],
				'stages'       => [ 'Patient Inquiry', 'Consultation Booked', 'Treatment Planned', 'Completed', 'Canceled' ],
			],
			'ecommerce' => [
				'key'          => 'ecommerce',
				'label'        => 'E-Commerce & D2C Brands',
				'description'  => 'Direct-to-consumer lifestyle brand delivering premium curated goods, rapid worldwide shipping, and effortless returns.',
				'selling_goal' => 'Recommend products based on user style/needs, answer questions on sizing/shipping/returns, offer first-order promo incentives, and recover abandoned interest.',
				'greeting'     => 'Hey! Looking for the perfect style or have questions about your order? I can help find the right fit or grab you a first-time discount code!',
				'persona'      => 'You are an enthusiastic, trend-conscious Personal Shopping Assistant. You know the product catalog inside out, provide concise recommendations, and make checkout seamless.',
				'interests'    => [ 'Product Sizing Guide', 'Discount Code Request', 'Order Tracking & Delivery', 'Return & Exchange Policy', 'Bulk Wholesale Order' ],
				'stages'       => [ 'Shopper Inquiry', 'Cart Assisted', 'Discount Applied', 'Order Placed', 'Post-Sale Support' ],
			],
			'consulting' => [
				'key'          => 'consulting',
				'label'        => 'Consulting & Professional Services',
				'description'  => 'Strategic management consultancy advising executives and enterprises on corporate strategy, fractional leadership, financial advisory, and risk mitigation.',
				'selling_goal' => 'Qualify enterprise leadership prospects, capture business pain points and annual turnover, and book a confidential executive discovery session.',
				'greeting'     => 'Welcome. Are you looking for strategic corporate advisory, fractional leadership, or business transformation consulting?',
				'persona'      => 'You are a Senior Strategic Advisory Partner. You speak with intellectual rigor, confidentiality, and executive polish. Ask incisive diagnostic questions to uncover core business friction points.',
				'interests'    => [ 'Strategic Business Audit', 'Tax & Compliance Review', 'Fractional CFO / Advisory', 'M&A Due Diligence', 'Corporate Restructuring' ],
				'stages'       => [ 'Initial Inquiry', 'Discovery Call', 'Proposal & Scope', 'Retainer Signed', 'Inactive' ],
			],
		];
	}

	/**
	 * Apply a blueprint configuration across the entire Sales OS setup.
	 */
	public static function apply( string $blueprintKey ): bool {
		$all = self::all();
		if ( ! isset( $all[ $blueprintKey ] ) ) {
			return false;
		}

		$b = $all[ $blueprintKey ];

		update_option( 'vmsos_agent_name', match ( $blueprintKey ) {
			'creative_agency' => 'Leo (Growth Strategist)',
			'real_estate'     => 'Victoria',
			'saas_software'   => 'Alex (Solutions Engineer)',
			'medical_dental'  => 'Sarah (Care Coordinator)',
			'ecommerce'       => 'Chloe (Shopping Stylist)',
			'consulting'      => 'Marcus (Senior Partner)',
			default           => 'Ava',
		} );

		update_option( 'vmsos_business_context', $b['description'] );
		update_option( 'vmsos_business_description', $b['description'] );
		update_option( 'vmsos_selling_goal', $b['selling_goal'] );
		update_option( 'vmsos_greeting', $b['greeting'] );
		update_option( 'vmsos_assistant_persona', $b['persona'] );
		update_option( 'vmsos_interests', $b['interests'] );

		global $wpdb;
		if ( isset( $wpdb ) && $wpdb && method_exists( $wpdb, 'get_var' ) ) {
			$brandId = class_exists( BrandContext::class ) ? BrandContext::current() : 1;

			// Update or insert default sales agent persona in vmsos_agents
			$agentTable = $wpdb->prefix . 'vmsos_agents';
			$existing   = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM $agentTable WHERE brand_id = %d AND slug = %s", $brandId, 'sales-closer' )
			);

			$agentData = [
				'brand_id'      => $brandId,
				'slug'          => 'sales-closer',
				'name'          => sprintf( '%s AI Sales Specialist', $b['label'] ),
				'role'          => $b['persona'],
				'capabilities'  => wp_json_encode( [ 'public', 'internal' ] ),
				'tools'         => wp_json_encode( [ 'capture_lead', 'search_knowledge', 'book_calendar', 'query_catalog', 'calculate_quote', 'request_human' ] ),
				'system_prompt' => $b['persona'] . "\n\nPRIMARY GOAL: " . $b['selling_goal'],
				'is_active'     => 1,
				'updated_at'    => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
			];

			if ( $existing ) {
				$wpdb->update( $agentTable, $agentData, [ 'id' => (int) $existing ] );
			} else {
				$agentData['created_at'] = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
				$wpdb->insert( $agentTable, $agentData );
			}
		}

		return true;
	}
}
