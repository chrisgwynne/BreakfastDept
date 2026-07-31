// Typed Breakfast Admin portfolio / case-study API.
import { api } from './api'

export interface PortfolioStub {
  id: string
  slug: string
  title: string
  status: string
  hasChanges: boolean
  client: string
  updated: string
}

export interface Screenshot {
  uuid: string
  page_label: string
  viewport: string
  status: string
  is_stub: boolean
  version: number
  public_use_approved: boolean
  privacy_reviewed: boolean
  width: number
  height: number
  file: string
}

export interface Derivative {
  uuid: string
  kind: string
  label: string
  width: number
  height: number
  needs_review: boolean
  source_name: string
}

export interface MediaItem {
  filename: string
  url: string
  width: number
  height: number
  bytes: number
  alt: string
}

export interface Warning {
  category: string
  message: string
}

export interface CaseStudy {
  id: string
  slug: string
  title: string
  status: string
  hasChanges: boolean
  fields: Record<string, string>
  artDirection: { fields: Record<string, string>; preset: string; data: Record<string, string> }
  blocks: string
  media: MediaItem[]
  approvedHosts: string[]
  approvedPaths: string[]
  screenshots: Screenshot[]
  derivatives: Derivative[]
  warnings: Warning[]
  publishable: boolean
  blockers: string[]
  previewUrl: string
}

export interface CaptureHealth {
  driver: string
  configured: boolean
  browserAvailable: boolean | null
  lastSuccessful: string | null
  approvedHostCount: number
  screenshotCount: number
  storageBytes: number
}

const P = '/portfolio'

export const portfolio = {
  list: () => api.get<PortfolioStub[]>(P),
  create: (title: string, opts: Record<string, unknown> = {}) => api.post<CaseStudy>(P, { title, ...opts }),
  get: (id: string) => api.get<CaseStudy>(`${P}/${id}`),
  save: (id: string, patch: Record<string, unknown>) => api.patch<CaseStudy>(`${P}/${id}`, patch),
  publish: (id: string) => api.post<CaseStudy>(`${P}/${id}/publish`),
  unpublish: (id: string) => api.post<CaseStudy>(`${P}/${id}/unpublish`),
  capture: (id: string, body: Record<string, unknown>) => api.post<Screenshot>(`${P}/${id}/capture`, body),
  approve: (id: string, sid: string) => api.post<Screenshot>(`${P}/${id}/screenshot/${sid}/approve`),
  privacy: (id: string, sid: string, notes = '') => api.post<Screenshot>(`${P}/${id}/screenshot/${sid}/privacy`, { notes }),
  attach: (id: string, sid: string) => api.post<CaseStudy>(`${P}/${id}/screenshot/${sid}/attach`),
  derivative: (id: string, body: Record<string, unknown>) => api.post<Derivative>(`${P}/${id}/derivative`, body),
  previewLink: (id: string) => api.get<{ url: string; expires: string }>(`${P}/${id}/preview-link`),
  revokePreview: (id: string) => api.post<{ ok: boolean }>(`${P}/${id}/revoke-preview`),
  captureHealth: () => api.get<CaptureHealth>(`${P}/capture-health`),
}

// The curated art-direction option vocabularies (mirror ArtDirection in PHP).
export const AD_OPTIONS: Record<string, string[]> = {
  preset: ['bold-editorial', 'playful-collage', 'quiet-premium', 'image-first', 'device-showcase', 'before-after', 'typography-led', 'colour-led', 'story-driven'],
  hero: ['cinematic', 'layered-devices', 'editorial', 'collage', 'minimal', 'split'],
  ending: ['giant-shot', 'testimonial', 'results', 'visit', 'lessons', 'next-teaser', 'motif', 'mobile-row'],
  accent: ['ink', 'cream', 'paper', 'white', 'yellow', 'butter', 'purple', 'plum', 'coral', 'blush', 'sage', 'forest', 'midnight', 'sky', 'terracotta', 'sand', 'mint', 'charcoal'],
  secondary: ['ink', 'yellow', 'purple', 'coral', 'forest', 'midnight', 'terracotta', 'plum', 'sky', 'sand'],
  bg: ['cream', 'paper', 'white', 'butter', 'midnight', 'ink', 'forest', 'plum'],
  text: ['ink', 'white', 'cream'],
  display: ['grotesk-tight', 'grotesk-wide', 'mono-caps'],
  body: ['sans', 'mono'],
  density: ['airy', 'balanced', 'tight'],
  corner: ['sharp', 'soft', 'round', 'pill'],
  border: ['none', 'thin', 'thick', 'heavy'],
  image_scale: ['contained', 'bleed', 'oversized'],
  rotation: ['none', 'subtle', 'playful', 'wild'],
  caption: ['quiet', 'mono-tag', 'handwritten', 'boxed'],
  pullquote: ['plain', 'giant', 'marks', 'panel', 'rotated', 'over-image'],
  animation: ['quiet', 'playful-stagger', 'mask-reveal', 'parallax-lite', 'fade', 'device-assembly', 'none'],
  transition: ['hard-cut', 'slide-panel', 'angled', 'oversized-rule', 'overlap', 'whitespace'],
  motif: ['none', 'dots', 'grid', 'wave', 'star', 'arrow', 'underline', 'sticker'],
}
