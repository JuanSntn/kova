export type NavigationItem = {
  key: string
  label: string
  path: string
  icon: string
  order: number
}

export type NavigationSection = {
  key: string
  label: string | null
  order: number
  items: NavigationItem[]
}

export type NavigationResponse = {
  sections: NavigationSection[]
}