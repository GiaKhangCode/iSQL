import React from "react";
import { createRoot } from "react-dom/client";
import { createBrowserRouter, RouterProvider } from "react-router-dom";
import { App } from "@/app/App";
import { AppProviders } from "@/app/providers/AppProviders";
import "@/styles.css";
const router = createBrowserRouter([{ path: "*", element: (
      <AppProviders><App /></AppProviders>
)}]);
createRoot(document.getElementById("root")!).render(
  <React.StrictMode><RouterProvider router={router} /></React.StrictMode>,
);
