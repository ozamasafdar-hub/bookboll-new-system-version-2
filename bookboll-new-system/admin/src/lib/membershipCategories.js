/**
 * membershipCategories.js — taxonomy for membership programs (class_programs).
 *
 * A program has a MAIN category (`class_programs.category`: gym | class | academy)
 * and a TYPE / subcategory (kept in the existing `class_programs.sport_category`
 * column, so non-sport types like "Calisthenics" or "Spinning" live there too).
 * Default category is 'academy' so existing sport-style programs keep working
 * before the category migration is applied.
 */

export const MEMBERSHIP_CATEGORIES = [
  { key: 'club', label: 'Club', blurb: 'Sports clubs and association memberships', subcategories: ['Football','Padel','Cricket','Badminton','Tennis','Other'] },
  { key: 'camp', label: 'Camp', blurb: 'Holiday camps and seasonal programmes', subcategories: ['Multi-sport','Football','Swimming','Cricket','Other'] },
  { key: 'coaching', label: 'Coaching', blurb: 'Personal coaching and lesson packs', subcategories: ['Padel','Tennis','Badminton','Fitness','Other'] },
  { key: 'other', label: 'Other', blurb: 'Other memberships and activity programmes', subcategories: ['Other'] },
  {
    key: 'gym',
    label: 'Gym',
    blurb: 'Facility access memberships',
    subcategories: [
      'General Gym', 'Calisthenics', 'Boxing', 'CrossFit', 'Powerlifting',
      'Weightlifting', 'Bodybuilding', 'Functional Training', 'MMA / Martial Arts',
      'Yoga Studio', 'Pilates Studio', 'Climbing', 'Swimming', 'Other',
    ],
  },
  {
    key: 'class',
    label: 'Class',
    blurb: 'Recurring group classes',
    subcategories: [
      'Yoga', 'Pilates', 'Spinning / Cycling', 'Zumba', 'HIIT', 'Aerobics',
      'Dance', 'Boxing / Kickboxing', 'Martial Arts', 'Swimming', 'Bootcamp',
      'Barre', 'CrossFit', 'Strength Training', 'Meditation', 'Other',
    ],
  },
  {
    key: 'academy',
    label: 'Academy',
    blurb: 'Coaching & skill development',
    subcategories: [
      'Football', 'Padel', 'Tennis', 'Basketball', 'Swimming', 'Cricket',
      'Volleyball', 'Badminton', 'Squash', 'Gymnastics', 'Athletics',
      'Martial Arts', 'Boxing', 'Skating', 'Chess', 'Music', 'Dance',
      'Art', 'Coding / STEM', 'Other',
    ],
  },
]

export const MEMBERSHIP_CATEGORY_BY_KEY = Object.fromEntries(MEMBERSHIP_CATEGORIES.map(c => [c.key, c]))

// Who a program is for — shown as a tag on the customer site. Two axes by category:
//   class / academy → AGE targeting (kids / adults / both)
//   gym            → ACCESS (everyone / ladies only / men only)
export const AUDIENCES = [
  { key: 'kids',   label: 'Kids',          short: 'Kids' },
  { key: 'adults', label: 'Adults',        short: 'Adults' },
  { key: 'both',   label: 'Kids & Adults', short: 'All ages' },
]
export const GYM_AUDIENCES = [
  { key: 'everyone', label: 'Everyone',    short: 'Everyone' },
  { key: 'ladies',   label: 'Ladies only', short: 'Ladies only' },
  { key: 'men',      label: 'Men only',    short: 'Men only' },
]
const AUDIENCE_BY_KEY = Object.fromEntries([...AUDIENCES, ...GYM_AUDIENCES].map(a => [a.key, a]))
export function audienceLabel(key) { return AUDIENCE_BY_KEY[key]?.label || '' }

// ── Gender policy — a SEPARATE dimension from audience (age): who can join.
// Stored in class_programs.gender_policy ('mixed' default). Gyms don't get a second
// selector — their access audience (everyone/ladies/men) derives it instead.
export const GENDER_POLICIES = [
  { key: 'mixed', label: 'Mixed' },
  { key: 'men',   label: 'Men only' },
  { key: 'women', label: 'Women only' },
]
export function normGenderPolicy(v) {
  const k = String(v || '').toLowerCase()
  if (k === 'men' || k === 'male') return 'men'
  if (k === 'women' || k === 'ladies' || k === 'female') return 'women'
  return 'mixed'
}
export function genderPolicyLabel(key) {
  return GENDER_POLICIES.find(g => g.key === normGenderPolicy(key))?.label || 'Mixed'
}
// Effective policy for display. Falls back to the gym access audience (ladies/men)
// so gym tags stay correct on rows saved before the gender_policy migration ran.
export function effectiveGenderPolicy(p) {
  if (p?.gender_policy) return normGenderPolicy(p.gender_policy)
  const a = String(p?.audience || '').toLowerCase()
  if (a === 'ladies') return 'women'
  if (a === 'men') return 'men'
  return 'mixed'
}

// The audience options a category offers (gym = access, class/academy = age).
export function audienceOptionsFor(catKey) { return normMembershipCategory(catKey) === 'gym' ? GYM_AUDIENCES : AUDIENCES }
// Class & academy MUST declare an age audience (kids/adults/both). Gyms default to 'everyone'.
export function categoryNeedsAudience(catKey) { return ['class', 'academy'].includes(normMembershipCategory(catKey)) }
// Gym = facility-access membership (no coach / capacity / sessions / fixed seasons).
export function categoryIsGym(catKey) { return normMembershipCategory(catKey) === 'gym' }
// Does a program's audience match a storefront filter chip? 'all' matches all; 'adults' also
// covers gym audiences (a gym membership is an adult membership). The optional `gender`
// arg ('mixed'|'men'|'women') lets women-/men-only classes match those chips too.
export function audienceMatches(filterKey, audience, gender) {
  const a = audience || ''
  const g = gender || ''
  if (filterKey === 'kids')   return a === 'kids' || a === 'both'
  if (filterKey === 'adults') return a === 'adults' || a === 'both' || a === 'everyone' || a === 'ladies' || a === 'men'
  if (filterKey === 'ladies') return a === 'ladies' || g === 'women'
  if (filterKey === 'men')    return a === 'men' || g === 'men'
  return true
}

// Normalise any stored value to a known category key (missing/legacy → 'academy').
export function normMembershipCategory(key) {
  const k = key === 'classes' ? 'class' : String(key || '').toLowerCase()
  return MEMBERSHIP_CATEGORY_BY_KEY[k] ? k : 'academy'
}
export function membershipCategoryLabel(key) { return MEMBERSHIP_CATEGORY_BY_KEY[normMembershipCategory(key)].label }
export function membershipSubcategoriesFor(key) { return MEMBERSHIP_CATEGORY_BY_KEY[normMembershipCategory(key)].subcategories }
