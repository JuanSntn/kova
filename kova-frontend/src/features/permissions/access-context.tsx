import { createContext, type ReactNode, useContext } from 'react'

const AccessContext = createContext<string[]>([])

export function AccessProvider({
  abilities,
  children,
}: {
  abilities: string[]
  children: ReactNode
}) {
  return (
    <AccessContext.Provider value={abilities}>
      {children}
    </AccessContext.Provider>
  )
}

export function useAbilities() {
  return useContext(AccessContext)
}

export function useAbility(ability: string) {
  return useAbilities().includes(ability)
}