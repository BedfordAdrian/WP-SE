import { seededRandom, hashString } from '../utils/id.js';
import { daysBetween } from '../utils/time.js';
import { suggestHashtags } from './recommendations.js';
import { POST_TYPES } from '../domain/posts.js';

/**
 * The Content Studio turns structured book metadata into ready-to-post social
 * copy. It is a transparent, template-driven engine (no external LLM required),
 * which keeps generation deterministic, testable and free. Each post type has
 * several hand-written variants so a campaign never repeats itself, and copy is
 * fitted to each platform's character budget.
 */

export const PLATFORM_LIMITS = {
  twitter: 280,
  bluesky: 300,
  threads: 500,
  instagram: 2200,
  facebook: 5000,
  tiktok: 2200,
  newsletter: 100000,
};

const emojiFor = {
  teaser: '👀',
  quote_card: '📖',
  cover_reveal: '✨',
  preorder_push: '🛒',
  countdown: '⏳',
  launch_day: '🎉',
  review_highlight: '⭐',
  behind_the_scenes: '🎬',
  trope_appeal: '💫',
  character_spotlight: '🎭',
  giveaway: '🎁',
  newsletter_cta: '💌',
  sale_announcement: '🔥',
  milestone: '🏆',
  question_engagement: '💬',
};

function pick(arr, rng, fallback = '') {
  if (!arr || arr.length === 0) return fallback;
  return arr[Math.floor(rng() * arr.length)];
}

function firstSentence(text, max = 180) {
  if (!text) return '';
  const match = text.match(/[^.!?]+[.!?]/);
  const sentence = (match ? match[0] : text).trim();
  return sentence.length > max ? `${sentence.slice(0, max - 1).trimEnd()}…` : sentence;
}

function bookPrice(book) {
  if (typeof book.price === 'number') return book.price;
  const p = book.prices || {};
  for (const fmt of ['ebook', 'paperback', 'hardcover', 'audiobook']) {
    if (typeof p[fmt] === 'number') return p[fmt];
  }
  return null;
}

function priceString(book, currency = '$') {
  const val = bookPrice(book);
  if (val === null || val === undefined) return '';
  return val === 0 ? 'FREE' : `${currency}${val.toFixed(2)}`;
}

const LINK_ORDER = ['universal', 'booklinker', 'amazon', 'linktree', 'apple', 'kobo', 'barnesnoble', 'audible', 'signed'];

// Pick the buy link used in generated posts: the book's preferred link if set
// and present, otherwise the best available by broad-reach order.
function chosenLink(book) {
  const links = book.buyLinks || {};
  if (book.preferredLink && links[book.preferredLink]) {
    return { url: links[book.preferredLink], key: book.preferredLink };
  }
  for (const key of LINK_ORDER) {
    if (links[key]) return { url: links[key], key };
  }
  return { url: '', key: '' };
}

function primaryLink(book) {
  return chosenLink(book).url;
}

function ctaFor(book, author) {
  const { url, key } = chosenLink(book);
  if (book.status === 'released' || book.status === 'preorder') {
    const verb = key === 'signed'
      ? 'Order a signed copy'
      : (book.status === 'preorder' ? 'Pre-order now' : 'Grab your copy');
    if (url) return `${verb}: ${url}`;
    return book.status === 'preorder' ? 'Pre-order available now!' : 'Available now everywhere books are sold.';
  }
  return author?.website
    ? `Join my newsletter for the release date: ${author.website}`
    : 'Follow for the release date!';
}

/**
 * Build the working context handed to every template variant. Everything a
 * variant might need is pre-resolved here so variants stay one-liners and never
 * emit `undefined`.
 */
function buildContext({ author, book, campaign, referenceDate, rng, currency = '$' }) {
  const title = book.title || 'my new book';
  const trope = pick(book.tropes, rng);
  const quote = pick(book.quotes, rng);
  const comp = pick(book.comps, rng);
  const review = pick(book.reviews, rng);
  const daysToRelease = book.releaseDate
    ? daysBetween(referenceDate || new Date(), book.releaseDate)
    : null;

  return {
    penName: author?.penName || 'the author',
    title,
    tagline: book.tagline || '',
    hook: firstSentence(book.blurb) || book.tagline || `You won't want to put ${title} down.`,
    genre: book.genre || 'book',
    trope,
    quote,
    comp,
    review,
    reviewText: review ? firstSentence(review.text, 160) : '',
    reviewStars: review ? '⭐'.repeat(review.rating || 5) : '⭐⭐⭐⭐⭐',
    daysToRelease,
    price: priceString(book, currency),
    cta: ctaFor(book, author),
    link: primaryLink(book),
    website: author?.website || '',
    imprint: book.publisher || '',
  };
}

// Each entry is an array of variant builders. Variants receive the resolved ctx.
const TEMPLATES = {
  teaser: [
    (c) => `What if ${c.hook.charAt(0).toLowerCase()}${c.hook.slice(1)}\n\n"${c.title}" — coming for your heart (and your sleep schedule).`,
    (c) => `${c.tagline || c.hook}\n\nThis is the ${c.genre.toLowerCase()} I've been dying to share with you. Meet "${c.title}."`,
    (c) => `Some stories whisper. This one grabs you by the collar.\n\n"${c.title}" ${c.comp ? `— for readers who loved ${c.comp}.` : ''}`.trim(),
  ],
  quote_card: [
    (c) => c.quote ? `"${c.quote}"\n\n— from "${c.title}"` : `A line I can't stop thinking about, from "${c.title}." (Screenshot-worthy pages inside.)`,
    (c) => c.quote ? `${c.quote}\n\nJust one of the moments waiting for you in "${c.title}."` : `The kind of sentence you dog-ear. "${c.title}" is full of them.`,
  ],
  cover_reveal: [
    (c) => `IT'S HERE. 🎉 The cover for "${c.title}" — and I am OBSESSED.\n\n${c.tagline || c.hook}\n\nWhat do you think?`,
    (c) => `Say hello to "${c.title}." 👋 Months of work, and this cover captures it perfectly.\n\n${c.comp ? `If ${c.comp} lives on your shelf, make room.` : 'Coming soon.'}`,
  ],
  preorder_push: [
    (c) => `Pre-orders are LIVE for "${c.title}." 🛒\n\nEvery pre-order tells the algorithm this book matters on release day — and it means the world to me.\n\n${c.cta}`,
    (c) => `${c.hook}\n\nDon't wait for release day — pre-order "${c.title}" now and it lands on your device the moment it's out. ${c.cta}`,
  ],
  countdown: [
    (c) => {
      const d = c.daysToRelease;
      if (d === 0) return `TODAY. "${c.title}" is HERE. 🎉 ${c.cta}`;
      if (d === 1) return `1 SLEEP to go. 😱 "${c.title}" releases tomorrow. Are you ready? ${c.cta}`;
      if (typeof d === 'number' && d > 0) return `${d} days until "${c.title}." ⏳\n\n${c.tagline || c.hook}\n\n${c.cta}`;
      return `Release day is almost here for "${c.title}"! ${c.cta}`;
    },
  ],
  launch_day: [
    (c) => `IT'S RELEASE DAY!!! 🎉📚\n\n"${c.title}" is officially out in the world${c.imprint ? ` from ${c.imprint}` : ''}. ${c.hook}\n\n${c.cta}`,
    (c) => `The day is finally here. "${c.title}" is LIVE. 🎉\n\nThank you for being here for this. Now go meet ${c.trope ? `the ${c.trope}` : 'these characters'} I love so much.\n\n${c.cta}`,
  ],
  review_highlight: [
    (c) => c.reviewText
      ? `${c.reviewStars}\n\n"${c.reviewText}"\n\nReviews like this make it all worth it. "${c.title}" — ${c.cta}`
      : `The early reviews for "${c.title}" are rolling in and I'm floored. ${c.reviewStars} ${c.cta}`,
    (c) => c.reviewText
      ? `A reader just said this about "${c.title}":\n\n"${c.reviewText}" ${c.reviewStars}\n\n${c.cta}`
      : `Readers are loving "${c.title}." ${c.reviewStars} Have you met it yet? ${c.cta}`,
  ],
  behind_the_scenes: [
    (c) => `Behind the scenes of "${c.title}": ${c.trope ? `I wrote the ${c.trope} scene four times before it felt right.` : 'some chapters took a dozen drafts to get right.'} 🎬\n\nWriting is rewriting. Worth every pass.`,
    (c) => `People ask where "${c.title}" came from. The honest answer: ${c.hook.toLowerCase()} That question wouldn't leave me alone until I wrote it.`,
  ],
  trope_appeal: [
    (c) => c.trope
      ? `If you love ${c.trope}, "${c.title}" was written for you. 💫\n\n${c.cta}`
      : `Tell me your favorite trope and I'll tell you why "${c.title}" delivers. 💫`,
    (c) => c.trope
      ? `POV: you open "${c.title}" and it's ${c.trope}, done right. 😌\n\n${c.cta}`
      : `"${c.title}" checks every box on your ${c.genre.toLowerCase()} wishlist. ${c.cta}`,
  ],
  character_spotlight: [
    (c) => `Character spotlight 🎭 The heart of "${c.title}" is a character who ${c.trope ? `lives and breathes ${c.trope}` : 'refuses to be who everyone expects'}.\n\nYou're going to want to know them.`,
    (c) => `Some characters you write. Others move in and refuse to leave. The lead of "${c.title}" is firmly the second kind. 🎭`,
  ],
  giveaway: [
    (c) => `🎁 GIVEAWAY! I'm giving away signed copies of "${c.title}."\n\nTo enter: follow, like & tag a friend who needs their next ${c.genre.toLowerCase()} obsession. Winner picked soon!`,
    (c) => `🎁 Want to win "${c.title}" before release? Follow + repost to enter. Spreading the word is everything for an indie author — thank you. 🙏`,
  ],
  newsletter_cta: [
    (c) => `My newsletter subscribers get cover reveals, bonus scenes, and early looks first. 💌\n\n${c.website ? `Join us: ${c.website}` : 'Link in bio to join.'}`,
    (c) => `Never miss a release. 💌 I send one thoughtful email a month — no spam, just stories.\n\n${c.website ? `Sign up: ${c.website}` : 'Newsletter link in bio.'}`,
  ],
  sale_announcement: [
    (c) => `🔥 SALE! "${c.title}" is ${c.price || 'on sale'} for a limited time.\n\n${c.hook}\n\n${c.cta}`,
    (c) => `Been eyeing "${c.title}"? Now's the moment — it's ${c.price || 'discounted'} this week only. 🔥 ${c.cta}`,
  ],
  milestone: [
    (c) => `I still can't believe it — "${c.title}" just hit a milestone I dreamed about. 🏆 Thank you for reading, reviewing, and shouting about it. This is all you.`,
    (c) => `Pinch me. 🏆 "${c.title}" wouldn't be here without this community. Whatever comes next, I'm so grateful you're part of it.`,
  ],
  question_engagement: [
    (c) => `Question for the timeline 💬: what's the last ${c.genre.toLowerCase()} that kept you up past midnight? (I'll go first: writing "${c.title}" did it to me.)`,
    (c) => `Help me settle a debate 💬: ${c.trope ? `is ${c.trope} the best trope, or THE best trope?` : 'what makes you one-click a book instantly?'} Reply below 👇`,
  ],
};

/**
 * Fit a body + hashtags to a platform's character budget. Returns possibly
 * trimmed body/hashtags plus the assembled preview text.
 */
export function fitToPlatform(body, hashtags, platform) {
  const limit = PLATFORM_LIMITS[platform] ?? 2200;
  let outBody = body.trim();
  const tagStr = hashtags.length ? `\n\n${hashtags.join(' ')}` : '';

  if (outBody.length + tagStr.length <= limit) {
    return { body: outBody, hashtags, text: outBody + tagStr };
  }

  // Try trimming hashtags first (keep body intact).
  const keptTags = [];
  for (const tag of hashtags) {
    const candidate = keptTags.concat(tag);
    const candidateStr = `\n\n${candidate.join(' ')}`;
    if (outBody.length + candidateStr.length <= limit) keptTags.push(tag);
    else break;
  }
  let keptStr = keptTags.length ? `\n\n${keptTags.join(' ')}` : '';

  // If body alone still exceeds the limit, truncate the body and drop tags.
  if (outBody.length + keptStr.length > limit) {
    outBody = `${outBody.slice(0, Math.max(0, limit - 1)).trimEnd()}…`;
    keptTags.length = 0;
    keptStr = '';
  }
  return { body: outBody, hashtags: keptTags, text: outBody + keptStr };
}

/**
 * Generate content for a single post. Returns an object shaped like a post
 * (body/hashtags/cta/mediaSuggestion/type/platform) WITHOUT persisting it.
 */
export function generateContent(store, options = {}) {
  const {
    bookId,
    postType = 'teaser',
    platform = 'twitter',
    campaignId = null,
    referenceDate = null,
    seed,
    variantIndex,
  } = options;

  if (!POST_TYPES.includes(postType)) {
    const err = new Error(`Unknown post type: ${postType}`);
    err.status = 400;
    throw err;
  }
  if (!PLATFORM_LIMITS[platform]) {
    const err = new Error(`Unknown platform: ${platform}`);
    err.status = 400;
    throw err;
  }

  const author = store.getAuthor();
  const book = bookId ? store.get('books', bookId) : null;
  if (bookId && !book) {
    const err = new Error('bookId does not reference an existing book');
    err.status = 400;
    throw err;
  }
  const campaign = campaignId ? store.get('campaigns', campaignId) : null;
  const effectiveBook = book || { title: 'your book', genre: 'Fiction', status: 'draft' };

  const seedValue =
    typeof seed === 'number'
      ? seed
      : hashString(`${bookId || 'nobook'}:${postType}:${platform}:${seed ?? ''}`);
  const rng = seededRandom(seedValue);
  const currency = (store.getSettings && store.getSettings().currencySymbol) || '$';

  const ctx = buildContext({ author, book: effectiveBook, campaign, referenceDate, rng, currency });

  const variants = TEMPLATES[postType] || TEMPLATES.teaser;
  const idx =
    typeof variantIndex === 'number'
      ? ((variantIndex % variants.length) + variants.length) % variants.length
      : Math.floor(rng() * variants.length);
  const rawBody = variants[idx](ctx).trim();

  const hashtags = platform === 'newsletter' ? [] : suggestHashtags(effectiveBook, platform);
  const fitted = fitToPlatform(rawBody, hashtags, platform);

  return {
    type: postType,
    platform,
    bookId: book ? book.id : null,
    campaignId: campaign ? campaign.id : null,
    body: fitted.body,
    hashtags: fitted.hashtags,
    cta: ctx.cta,
    mediaSuggestion: suggestMedia(postType, ctx),
    imagePrompt: suggestImagePrompt(postType, platform, ctx),
    preview: fitted.text,
    emoji: emojiFor[postType] || '📚',
  };
}

// Social image aspect ratio per platform (helps the author target the crop).
const ASPECT = { twitter: '16:9', bluesky: '16:9', instagram: '4:5', facebook: '1.91:1', tiktok: '9:16', threads: '4:5', newsletter: '16:9' };

const GENRE_STYLE = {
  'Romantic Comedy': 'bright and playful, sunny pastel palette, warm upbeat rom-com energy, contemporary',
  Romance: 'romantic and dreamy, warm golden light, soft focus, intimate',
  Romantasy: 'lush fantasy-romance, jewel tones, ethereal magical glow, ornate detail',
  Fantasy: 'epic and atmospheric, dramatic lighting, rich saturated colour, sense of wonder',
  'Science Fiction': 'sleek and futuristic, cool tones with neon accents, cinematic sci-fi',
  Thriller: 'dark and tense, high contrast, cool desaturated palette, cinematic',
  Mystery: 'moody noir, shadowy, muted tones, intriguing',
  Horror: 'eerie and unsettling, dark palette, ominous atmosphere',
  'Historical Fiction': 'period-authentic, warm vintage tones, painterly',
  'Young Adult': 'vibrant and fresh, bold colour, energetic and youthful',
  'Literary Fiction': 'understated and elegant, muted natural palette, artful',
  Memoir: 'warm and authentic, natural light, personal',
  Nonfiction: 'clean and confident, bold minimal graphic style',
  'Self-Help': 'bright and uplifting, clean and minimal, optimistic',
};

function genreStyle(genre) {
  return GENRE_STYLE[genre] || 'clean, contemporary and on-brand';
}

function sceneFor(postType, ctx) {
  const trope = ctx.trope ? ` evoking "${ctx.trope}"` : '';
  const mood = ctx.tagline || ctx.hook || '';
  switch (postType) {
    case 'quote_card': return 'an elegant textured background with a soft gradient and generous empty space to overlay a short quote';
    case 'cover_reveal': return 'a dramatic cover-reveal composition: a closed hardcover book at a flattering three-quarter angle under soft studio light with a subtle sparkle, celebratory';
    case 'countdown': return 'a countdown-announcement background with the book and a calendar or clock motif and clear empty space for a large number';
    case 'launch_day': return 'a joyful book-launch flat-lay: the book surrounded by confetti, ribbon and a coffee cup, bright and festive, top-down';
    case 'preorder_push': return 'an enticing pre-order flat-lay: the book styled on a desk with a "coming soon" mood, bright';
    case 'review_highlight': return 'a clean testimonial background with a row of five gold stars and soft bokeh, with empty space for a short quote';
    case 'behind_the_scenes': return 'a cozy author writing-desk scene: laptop, notebook, coffee and plants in warm natural window light, candid and authentic';
    case 'trope_appeal': return `an evocative, cinematic mood image${trope}`;
    case 'character_spotlight': return `an atmospheric character-mood scene with no recognisable face${trope}, cinematic`;
    case 'giveaway': return 'an inviting giveaway prize flat-lay: a signed book with ribbon, a bookmark and small gifts on a styled surface, top-down';
    case 'newsletter_cta': return 'a tempting reader-magnet mockup: an e-reader and a printed freebie on a cozy styled desk';
    case 'sale_announcement': return 'a bold, energetic sale-announcement background with dynamic shapes and empty space for text';
    case 'milestone': return 'a celebratory milestone background with confetti and a warm glow, with empty space for text';
    case 'question_engagement': return 'a friendly conversational flat-lay: the book, a coffee and a subtle question-mark motif, with empty space for text';
    case 'teaser':
    default: return `an intriguing, cinematic teaser image${mood ? ` evoking "${mood}"` : ''}, atmospheric`;
  }
}

/**
 * A ready-to-paste text-to-image prompt (Midjourney / DALL·E / Stable Diffusion
 * / Canva) for the visual this post needs. Deliberately asks for NO text, since
 * image models render lettering poorly — the author overlays real copy.
 */
function suggestImagePrompt(postType, platform, ctx) {
  const scene = sceneFor(postType, ctx);
  const aspect = ASPECT[platform] || '4:5';
  const book = `the ${String(ctx.genre || 'book').toLowerCase()} "${ctx.title}"${ctx.tagline ? ` (${ctx.tagline})` : ''}`;
  const opener = scene.charAt(0).toUpperCase() + scene.slice(1);
  return `${opener}. Inspired by ${book}. Style: ${genreStyle(ctx.genre)}. ${aspect} social-media graphic, high quality and tasteful, no text, no lettering, no watermark.`;
}

function suggestMedia(postType, ctx) {
  switch (postType) {
    case 'quote_card':
      return `Quote graphic on a branded background: "${(ctx.quote || ctx.hook).slice(0, 80)}"`;
    case 'cover_reveal':
      return 'The full-resolution book cover (this is the star of the post).';
    case 'countdown':
      return `Countdown graphic showing "${ctx.daysToRelease ?? 'X'} days" over the cover.`;
    case 'launch_day':
      return 'Cover mockup on a device/shelf, or a short celebratory video.';
    case 'review_highlight':
      return 'Review screenshot styled as a graphic with a star rating.';
    case 'behind_the_scenes':
      return 'A candid writing-desk photo or a short talking-head clip.';
    case 'giveaway':
      return 'Photo of the physical prize (signed copy + any swag).';
    case 'newsletter_cta':
      return 'A mockup of the reader magnet / freebie subscribers receive.';
    default:
      return 'Book cover or an on-brand graphic.';
  }
}

export { TEMPLATES };
