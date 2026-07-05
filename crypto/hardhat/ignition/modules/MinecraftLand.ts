import { buildModule } from "@nomicfoundation/hardhat-ignition/modules";
import { parseEther } from "viem";

export default buildModule("MinecraftLandModule", (m) => {
  const mintPrice = m.getParameter("mintPrice", parseEther("10"));
  const baseTokenURI = m.getParameter("baseTokenURI", "");
  const minecraftLand = m.contract("MinecraftLand", [mintPrice, baseTokenURI]);

  return { minecraftLand };
});
