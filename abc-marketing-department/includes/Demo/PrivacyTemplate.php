<?php
/**
 * Editable client-facing UK privacy / AI-processing template.
 *
 * This is a STARTING POINT that requires legal review. It is not legal advice.
 *
 * @package ABCMD
 */

namespace ABCMD\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the default privacy template text.
 */
final class PrivacyTemplate {

	/**
	 * Default template markdown.
	 */
	public static function default_text(): string {
		return <<<'MD'
# Client Data & AI Processing Notice — TEMPLATE

> **This is a starting-point template, not legal advice.** ABC Book Marketing
> must have this reviewed by a qualified adviser before relying on it. Complete
> the bracketed fields for each author/book.

## 1. Who we are
ABC Book Marketing ("we", "the agency") acts as a data controller/processor for
the marketing services we provide to you in respect of the book(s) named in your
workspace.

## 2. UK GDPR basis
We process your data under the UK GDPR and the Data Protection Act 2018. Our
lawful bases are performance of our contract with you and our legitimate
interests in delivering effective marketing, balanced against your rights.

## 3. Categories of data
- Book and campaign metadata (titles, prices, targets, budgets).
- Sales, advertising, email and social performance data you or your retailers
  supply.
- Manuscript text or extracts, **only where you explicitly consent**.
- Business contact details. We avoid special-category personal data.

## 4. Purpose of processing
To analyse campaign performance, generate marketing recommendations, draft
campaign materials for your approval, and report to you.

## 5. AI subprocessors
Where you consent, we use OpenAI's API to assist with analysis and drafting.
- We send only the data categories you tick on the per-run data-sharing
  checklist.
- We do **not** send your full manuscript by default.
- OpenAI processes prompts to return a response; refer to OpenAI's then-current
  data-usage and retention terms. [Insert current reference and any data
  processing addendum.]

## 6. International data transfers
OpenAI may process data outside the UK. [State the transfer mechanism relied on,
e.g. UK IDTA / adequacy, and complete a transfer risk assessment.]

## 7. Web research
Where enabled and supported by the selected model, we may use AI-assisted web
search to inform strategy. Sources are recorded and shown to you.

## 8. Retention
We retain campaign history and reporting data for the duration of our engagement
and thereafter for [retention period], unless you ask us to delete it.

## 9. Security
Data is held in access-controlled systems. API keys are encrypted at rest.
Manuscript files are stored in protected, non-public storage.

## 10. Human review
All AI output is reviewed by a human before use. Nothing is published
automatically. AI recommendations are labelled Evidence-backed, Inferred or
Experimental, with a confidence level.

## 11. Limits of AI output
AI can be wrong, out of date, or omit context. Recommendations are suggestions
for professional judgement, not guarantees of results.

## 12. Your instructions & revocation
You may set or withdraw consent for any data category at any time. On withdrawal
we stop the relevant AI processing. [Explain how to contact us.]

## 13. Contact
[Agency contact name, email, postal address, and — if applicable — data
protection contact.]
MD;
	}
}
