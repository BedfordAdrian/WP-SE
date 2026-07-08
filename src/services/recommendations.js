/**
 * Recommendation heuristics: when to post, how many hashtags, and which tags to
 * use. These encode widely-cited book-marketing conventions as data so the
 * planner and content studio can lean on them. They are deliberately simple and
 * transparent — a real deployment would refine them from the author's own
 * analytics (see metrics.js for the raw material).
 */

// Recommended LOCAL posting hours (24h) per platform, roughly ordered best-first.
export const BEST_TIMES = {
  twitter: [9, 12, 17, 20],
  instagram: [11, 14, 19, 21],
  facebook: [9, 13, 19],
  tiktok: [7, 12, 19, 22],
  threads: [10, 13, 18, 21],
  newsletter: [8, 10],
};

// How many hashtags to attach per platform (0 = none / body only).
export const HASHTAG_LIMITS = {
  twitter: 3,
  instagram: 12,
  facebook: 3,
  tiktok: 5,
  threads: 5,
  newsletter: 0,
};

// Posting cadence guidance (posts per week) used by the planner during the
// steady phase of a campaign.
export const CADENCE_PER_WEEK = {
  twitter: 5,
  instagram: 4,
  facebook: 3,
  tiktok: 3,
  threads: 4,
  newsletter: 1,
};

const GENRE_HASHTAGS = {
  Romance: ['#Romance', '#RomanceBooks', '#RomanceReads', '#BookBoyfriend', '#HEA'],
  'Romantic Comedy': ['#RomCom', '#RomanticComedy', '#UpLit', '#BeachRead', '#FeelGood', '#WomensFiction'],
  Romantasy: ['#Romantasy', '#FantasyRomance', '#Booktok', '#FaeromanceReads', '#EnemiesToLovers'],
  Fantasy: ['#Fantasy', '#FantasyBooks', '#EpicFantasy', '#Bookdragon', '#SFF'],
  'Science Fiction': ['#SciFi', '#ScienceFiction', '#SFF', '#SpaceOpera', '#SciFiBooks'],
  Thriller: ['#Thriller', '#ThrillerBooks', '#Suspense', '#CrimeFiction', '#PageTurner'],
  Mystery: ['#Mystery', '#MysteryBooks', '#Whodunit', '#CozyMystery', '#CrimeReads'],
  Horror: ['#Horror', '#HorrorBooks', '#HorrorCommunity', '#Creepy', '#ScaryReads'],
  'Literary Fiction': ['#LiteraryFiction', '#LitFic', '#BookishThoughts', '#ReadingCommunity'],
  'Historical Fiction': ['#HistoricalFiction', '#HistFic', '#HistoricalRomance', '#BookishHistory'],
  'Young Adult': ['#YA', '#YoungAdult', '#YABooks', '#YALit', '#TeenReads'],
  Nonfiction: ['#Nonfiction', '#NonfictionBooks', '#ReadNonfiction'],
  Memoir: ['#Memoir', '#Memoirs', '#TrueStory', '#LifeStories'],
  'Self-Help': ['#SelfHelp', '#PersonalGrowth', '#SelfImprovement', '#Mindset'],
};

const COMMUNITY_HASHTAGS = {
  twitter: ['#BookTwitter', '#WritingCommunity', '#IndieAuthor', '#amreading'],
  instagram: ['#Bookstagram', '#BookstagramCommunity', '#IndieAuthor', '#amreading', '#booklover', '#currentlyreading'],
  facebook: ['#IndieAuthor', '#amreading'],
  tiktok: ['#BookTok', '#BookTokMadeMeReadIt', '#IndieAuthor'],
  threads: ['#Threadstagram', '#BookThreads', '#amreading'],
  newsletter: [],
};

function toTag(text) {
  const cleaned = String(text)
    .replace(/&/g, 'and')
    .replace(/[^a-zA-Z0-9 ]/g, '')
    .trim()
    .split(/\s+/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join('');
  return cleaned ? `#${cleaned}` : null;
}

/**
 * Suggest a de-duplicated, length-capped set of hashtags for a book on a given
 * platform. Order of preference: genre tags, trope/keyword tags, community tags.
 */
export function suggestHashtags(book = {}, platform = 'twitter') {
  const limit = HASHTAG_LIMITS[platform] ?? 3;
  if (limit === 0) return [];

  const genreTags = GENRE_HASHTAGS[book.genre] || [];
  const tropeTags = [...(book.tropes || []), ...(book.keywords || [])]
    .map(toTag)
    .filter(Boolean);
  const communityTags = COMMUNITY_HASHTAGS[platform] || [];

  const ordered = [...genreTags, ...tropeTags, ...communityTags];
  const seen = new Set();
  const result = [];
  for (const tag of ordered) {
    const key = tag.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    result.push(tag);
    if (result.length >= limit) break;
  }
  return result;
}

export function bestTimesFor(platform) {
  return BEST_TIMES[platform] || [9, 12, 18];
}

export function cadenceFor(platform) {
  return CADENCE_PER_WEEK[platform] ?? 3;
}
