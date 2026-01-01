# WP AI Chatbot LeadGen Pro

Enterprise-grade WordPress plugin that transforms how businesses engage with website visitors through intelligent, context-aware conversations using AI, RAG (Retrieval Augmented Generation), and comprehensive lead intelligence.

## Features

### 🤖 AI-Powered Conversations
- **Multi-Provider Support**: Seamlessly integrate with OpenAI, Anthropic (Claude), and Google Gemini
- **RAG Technology**: Retrieval Augmented Generation for context-aware responses
- **Intent Classification**: Automatically understand user intent and route conversations appropriately
- **Sentiment Analysis**: Monitor and respond to user sentiment in real-time
- **Contextual Questions**: Generate relevant follow-up questions based on conversation context
- **Conversation Memory**: Maintain context across multiple interactions

### 📊 Lead Generation & Intelligence
- **Smart Lead Capture**: Intelligent forms that capture leads at the right moment
- **Behavioral Scoring**: Track and score leads based on their behavior and engagement
- **Real-time Lead Scoring**: Instant lead qualification as visitors interact
- **Lead Enrichment**: Automatically enrich lead data from multiple sources
- **Lead Segmentation**: Categorize leads for better targeting
- **Lead Grading**: Comprehensive lead grading system for sales prioritization

### 📝 Content Management
- **Content Ingestion**: Automatically crawl and index your website content
- **Content Indexing**: Smart content indexing for better RAG performance
- **PDF Processing**: Extract and process content from PDF documents
- **WooCommerce Integration**: Specialized processing for WooCommerce products
- **Content Freshness Tracking**: Monitor and update content freshness
- **Duplicate Detection**: Identify and handle duplicate content
- **Sitemap Parser**: Automatic content discovery from XML sitemaps

### 🎯 Advanced Features
- **Multi-Channel Continuity**: Maintain conversation context across channels
- **Escalation Management**: Smart escalation to human agents when needed
- **Feedback Handling**: Collect and process user feedback
- **Conversation Export**: Export conversations for analysis
- **Accessibility**: Built with accessibility best practices
- **Multisite Support**: Full WordPress Multisite compatibility

### 🔒 Security & Performance
- **Encrypted API Key Storage**: Secure storage of API keys with encryption
- **Cost Tracking**: Monitor and track API usage costs
- **Fallback Management**: Automatic fallback to alternative providers
- **Retry Handling**: Intelligent retry logic for API failures
- **Rate Limiting**: Built-in rate limiting and throttling

## Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher (or MariaDB equivalent)
- **Network**: Multisite compatible

## Installation

### Manual Installation

1. Download or clone this repository:
   ```bash
   git clone https://github.com/imtiaz142/WP-AI-Chatbot-LeadGen-Pro.git
   ```

2. Upload the plugin folder to `/wp-content/plugins/` directory, or install via WordPress admin panel

3. Activate the plugin through the 'Plugins' menu in WordPress

4. Navigate to the plugin settings page to configure your API keys

### Configuration

1. **API Keys Setup**:
   - Go to WordPress Admin → WP AI Chatbot → Settings
   - Add your API keys for:
     - OpenAI (optional)
     - Anthropic/Claude (optional)
     - Google Gemini (optional)
   - API keys are encrypted and stored securely

2. **Content Ingestion**:
   - Configure content sources (sitemap, manual URLs, etc.)
   - Set up automatic content crawling schedule
   - Configure content processing options

3. **Lead Capture Settings**:
   - Configure lead capture forms
   - Set up lead scoring rules
   - Configure enrichment providers

4. **Chatbot Appearance**:
   - Customize chatbot widget appearance
   - Configure trigger settings
   - Set up conversation greetings

## Usage

### Basic Setup

1. After activation, the chatbot widget will appear on your website
2. Configure at least one AI provider API key in the settings
3. Set up content ingestion to enable RAG functionality
4. Customize the chatbot appearance and behavior in the admin panel

### Admin Features

- **Content Manager**: Manage and review ingested content
- **Leads Dashboard**: View, filter, and manage captured leads
- **Conversation Logs**: Review and export conversations
- **Analytics**: Monitor chatbot performance and lead generation metrics

## File Structure

```
wp-ai-chatbot-leadgen-pro/
├── assets/
│   ├── css/          # Stylesheets
│   └── js/           # JavaScript files
├── includes/
│   ├── admin/        # Admin interface classes
│   ├── content/      # Content ingestion and processing
│   ├── conversation/ # Conversation management
│   ├── leads/        # Lead generation and scoring
│   ├── providers/    # AI provider integrations
│   └── rag/          # RAG implementation
├── public/           # Public-facing templates
└── wp-ai-chatbot-leadgen-pro.php  # Main plugin file
```

## Security

- API keys are encrypted using WordPress encryption functions
- All user data is handled according to WordPress security best practices
- No API keys or sensitive data are stored in the repository
- Regular security updates and patches

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/imtiaz142/WP-AI-Chatbot-LeadGen-Pro).

## License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## Changelog

### 1.0.0
- Initial release
- Multi-provider AI support (OpenAI, Anthropic, Google)
- RAG implementation
- Lead generation and scoring
- Content ingestion and management
- Admin dashboard
- Multisite support

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Author

Developed by [imtiaz142](https://github.com/imtiaz142)

---

**Note**: This plugin requires API keys from at least one AI provider (OpenAI, Anthropic, or Google) to function. Make sure to add your API keys in the plugin settings after installation.

